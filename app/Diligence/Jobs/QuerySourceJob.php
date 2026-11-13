<?php

namespace App\Diligence\Jobs;

use App\Diligence\Actions\FailDiligenceItem;
use App\Diligence\Actions\RecordSourceResult;
use App\Diligence\Models\DiligenceItem;
use App\Diligence\States\Item\AwaitingCallback;
use App\Diligence\States\Item\Pending;
use App\Diligence\States\Item\Running;
use App\Diligence\Support\FanIn;
use App\Sources\Data\ProviderResponse;
use App\Sources\Exceptions\PermanentSourceException;
use App\Sources\Exceptions\SourceException;
use App\Sources\Exceptions\TransientSourceException;
use App\Sources\SourceRegistry;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\Skip;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Consulta UMA fonte para UM item.
 *
 * Três tipos de "não deu certo", três caminhos diferentes:
 *  - limite de taxa (RateLimited) ou circuito aberto (ThrottlesExceptions):
 *      release(delay) → mensagem vai para "{fila}.delay.{ms}" (TTL) → DLX devolve à fila principal.
 *      Não chama o fornecedor; não conta como tentativa real (item.attempts).
 *  - TransientSourceException: conta tentativa, backoff 30/120/600s pelo mesmo caminho TTL+DLX;
 *      esgotou → fail().
 *  - PermanentSourceException: fail() na hora.
 * fail() → basic_reject(requeue=false) → DLX da fila → "{fila}.dlq"  E  linha em failed_jobs.
 */
class QuerySourceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Exceções NÃO previstas (bug de código) — essas sim contam aqui e usam $backoff. */
    public int $maxExceptions = 3;

    public array $backoff = [30, 120, 600];

    public int $timeout = 90;

    public int $uniqueFor = 3600;

    /** Backoff da próxima tentativa transitória, lido pelo ThrottlesExceptions. */
    private int $transientBackoff = 30;

    private ?DiligenceItem $item = null;

    public function __construct(
        public int $itemId,
        public int $diligenceId,
        public string $provider,
        public string $product,
    ) {}

    /** Idempotência na publicação: a mesma fonte da mesma diligência não entra duas vezes na fila. */
    public function uniqueId(): string
    {
        return "{$this->diligenceId}:{$this->provider}.{$this->product}";
    }

    /**
     * Prazo em vez de $tries: cada release por rate limit incrementa attempts do Laravel,
     * então $tries=3 faria um job ser "falho" só por esperar a vez. Calculado no dispatch
     * e gravado no payload.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function middleware(): array
    {
        $key = "source:{$this->provider}";

        return [
            // Mensagem duplicada/reentregue de item já resolvido: ack e segue.
            Skip::when(fn () => $this->item()->status->isFinal()),

            // Cache de consulta recente ANTES do limitador: cache hit não gasta token.
            fn (self $job, $next) => $job->serveFromCache() ? null : $next($job),

            // Circuit breaker por fornecedor (chave compartilhada por todos os jobs dele).
            // Aberto → release sem chamar o fornecedor.
            (new ThrottlesExceptions(config('sources.circuit_breaker.max_failures'), config('sources.circuit_breaker.decay_seconds')))
                ->by($key)
                ->when(fn (Throwable $e) => $e instanceof SourceException)
                ->failWhen(PermanentSourceException::class)
                ->backoff(fn () => $this->transientBackoff / 60),

            // Token bucket por fornecedor (RateLimiter::for em AppServiceProvider).
            new RateLimited($key),
        ];
    }

    public function handle(SourceRegistry $registry, RecordSourceResult $record, FanIn $fanIn): void
    {
        $item = $this->item();
        $provider = $registry->provider($item->provider);

        if ($item->status->equals(Pending::class)) {
            $item->status->transitionTo(Running::class);
            $item->update(['started_at' => now()]);
        }
        $item->increment('attempts');

        try {
            $response = $provider->dispatch($item->diligence->entity(), $item->sourceConfig());
        } catch (TransientSourceException $e) {
            if ($item->attempts >= config('sources.max_attempts')) {
                $this->fail($e);

                return;
            }

            $backoff = config('sources.backoff_seconds');
            $this->transientBackoff = $backoff[$item->attempts - 1] ?? end($backoff);

            throw $e;
        }

        if ($response->protocol !== null) {
            // Fornecedor assíncrono: grava protocolo, sai e libera o worker.
            // Não chega no fan-in — quem chega é o webhook (ou o guard de timeout).
            $item->status->transitionTo(AwaitingCallback::class);
            $item->update(['params' => $item->params + ['protocol' => $response->protocol]]);

            return;
        }

        $record->handle($item, $provider, $response);
        $this->rememberRecent($item, $response);
        $this->arrive($fanIn);
    }

    /** Chamado pelo Laravel em qualquer falha definitiva (fail(), prazo, maxExceptions). */
    public function failed(?Throwable $e): void
    {
        $item = DiligenceItem::find($this->itemId);

        if ($item && app(FailDiligenceItem::class)->handle($item, $e ?? new \RuntimeException('falha desconhecida'))) {
            $this->arrive(app(FanIn::class));
        }
    }

    public function serveFromCache(): bool
    {
        $item = $this->item();
        $ttl = $item->params['cache_ttl'] ?? null;
        $payload = $ttl ? Cache::get($this->cacheKey($item)) : null;

        if ($payload === null) {
            return false;
        }

        if ($item->status->equals(Pending::class)) {
            $item->status->transitionTo(Running::class);
            $item->update(['started_at' => now()]);
        }
        app(RecordSourceResult::class)->handle(
            $item,
            app(SourceRegistry::class)->provider($item->provider),
            new ProviderResponse($payload),
            fromCache: true,
        );
        $this->arrive(app(FanIn::class));

        return true;
    }

    private function rememberRecent(DiligenceItem $item, ProviderResponse $response): void
    {
        if ($ttl = $item->params['cache_ttl'] ?? null) {
            Cache::put($this->cacheKey($item), $response->rawPayload, $ttl);
        }
    }

    private function cacheKey(DiligenceItem $item): string
    {
        return "source-cache:{$item->provider}:{$item->product}:{$item->diligence->entity_document}";
    }

    private function arrive(FanIn $fanIn): void
    {
        if ($fanIn->arrive($this->diligenceId)) {
            FinalizeDiligenceJob::dispatch($this->diligenceId)->onConnection('rabbitmq_control');
        }
    }

    private function item(): DiligenceItem
    {
        return $this->item ??= DiligenceItem::with('diligence')->findOrFail($this->itemId);
    }
}

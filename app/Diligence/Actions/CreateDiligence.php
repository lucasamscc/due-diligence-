<?php

namespace App\Diligence\Actions;

use App\Diligence\Models\Diligence;
use App\Diligence\States\Diligence\Running;
use App\Diligence\Support\Cnpj;
use App\Diligence\Support\FanIn;
use App\Sources\Data\SourceConfig;
use App\Sources\SourceRegistry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cria a diligência com o snapshot das fontes e faz o fan-out: um job por item,
 * cada um na connection (= routing key / fila) do seu fornecedor.
 */
class CreateDiligence
{
    public function __construct(
        private SourceRegistry $registry,
        private FanIn $fanIn,
        private DispatchItem $dispatchItem,
    ) {}

    public function handle(string $document): Diligence
    {
        if (! Cnpj::isValid($document)) {
            throw new InvalidArgumentException('Documento inválido: só CNPJ é consultável.');
        }

        $sources = $this->registry->all();

        $diligence = DB::transaction(function () use ($document, $sources) {
            $diligence = Diligence::create([
                'entity_document' => Cnpj::strip($document),
                'config_snapshot' => [
                    'snapshot_at' => now()->toIso8601String(),
                    'sources' => array_map(fn (SourceConfig $s) => $s->toArray(), $sources),
                ],
            ]);

            foreach ($sources as $source) {
                $diligence->items()->create([
                    'provider' => $source->provider,
                    'product' => $source->product,
                    'params' => $source->params,
                ]);
            }

            $diligence->status->transitionTo(Running::class);
            $diligence->update(['started_at' => now()]);

            return $diligence;
        });

        // Fora da transação, e nesta ordem:
        // 1) contador ANTES de publicar — senão um worker rápido decrementa um contador inexistente;
        // 2) publicar só depois do COMMIT — senão o worker busca um item que ainda não existe.
        $this->fanIn->expect($diligence->id, count($sources));

        foreach ($diligence->items as $item) {
            $this->dispatchItem->handle($item);
        }

        return $diligence;
    }
}

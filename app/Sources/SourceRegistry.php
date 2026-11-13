<?php

namespace App\Sources;

use App\Sources\BrasilApi\BrasilApiProvider;
use App\Sources\Contracts\RiskSourceProvider;
use App\Sources\Data\SourceConfig;
use App\Sources\Ibama\IbamaProvider;
use App\Sources\Transparencia\TransparenciaProvider;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Lista fixa, em código, de tudo que uma diligência consulta.
 * Fornecedor novo = pasta nova em Sources/ + uma linha aqui.
 */
class SourceRegistry
{
    /** @var array<string, class-string<RiskSourceProvider>> */
    private const PROVIDERS = [
        'brasilapi' => BrasilApiProvider::class,
        'transparencia' => TransparenciaProvider::class,
        'ibama' => IbamaProvider::class,
    ];

    public function __construct(private Container $container) {}

    /**
     * Fontes resolvidas no momento da criação — viram o config_snapshot.
     *
     * @return list<SourceConfig>
     */
    public function all(): array
    {
        return [
            new SourceConfig('brasilapi', 'cnpj', ['cache_ttl' => 86400]),
            new SourceConfig('transparencia', 'ceis', ['cache_ttl' => 21600]),
            new SourceConfig('transparencia', 'cnep', ['cache_ttl' => 21600]),
            new SourceConfig('transparencia', 'cepim', ['cache_ttl' => 21600]),
            new SourceConfig('ibama', 'embargos', ['dataset' => 'ibama_embargos']),
        ];
    }

    public function provider(string $provider): RiskSourceProvider
    {
        $class = self::PROVIDERS[$provider] ?? throw new InvalidArgumentException("Fornecedor desconhecido: {$provider}");

        return $this->container->make($class);
    }

    /** Connection RabbitMQ (= fila) do fornecedor. Infra, não escopo: não vai no snapshot. */
    public function connectionFor(string $provider): string
    {
        return config("sources.{$provider}.connection")
            ?? throw new InvalidArgumentException("Sem connection configurada para {$provider}");
    }

    /** @return list<string> */
    public function providers(): array
    {
        return array_keys(self::PROVIDERS);
    }
}

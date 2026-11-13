<?php

namespace App\Sources\Transparencia;

use App\Sources\Contracts\RiskSourceProvider;
use App\Sources\Data\DocumentType;
use App\Sources\Data\Entity;
use App\Sources\Data\ProviderResponse;
use App\Sources\Data\SourceConfig;
use App\Sources\Exceptions\PermanentSourceException;
use Illuminate\Support\Collection;

class TransparenciaProvider implements RiskSourceProvider
{
    public function __construct(
        private TransparenciaClient $client,
        private TransparenciaNormalizer $normalizer,
    ) {}

    public function dispatch(Entity $entity, SourceConfig $config): ProviderResponse
    {
        if ($entity->type !== DocumentType::Cnpj) {
            throw new PermanentSourceException('Consulta por CPF fora do escopo.');
        }

        return new ProviderResponse([
            'product' => $config->product,
            'records' => $this->client->sanctions($config->product, $entity->document),
        ]);
    }

    public function normalize(array $rawPayload): Collection
    {
        return $this->normalizer->normalize($rawPayload);
    }
}

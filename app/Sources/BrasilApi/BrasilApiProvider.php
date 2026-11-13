<?php

namespace App\Sources\BrasilApi;

use App\Sources\Contracts\RiskSourceProvider;
use App\Sources\Data\DocumentType;
use App\Sources\Data\Entity;
use App\Sources\Data\ProviderResponse;
use App\Sources\Data\SourceConfig;
use App\Sources\Exceptions\PermanentSourceException;
use Illuminate\Support\Collection;

class BrasilApiProvider implements RiskSourceProvider
{
    public function __construct(
        private BrasilApiClient $client,
        private BrasilApiNormalizer $normalizer,
    ) {}

    public function dispatch(Entity $entity, SourceConfig $config): ProviderResponse
    {
        if ($entity->type !== DocumentType::Cnpj) {
            throw new PermanentSourceException('BrasilAPI só consulta CNPJ.');
        }

        return new ProviderResponse($this->client->cnpj($entity->document));
    }

    public function normalize(array $rawPayload): Collection
    {
        return $this->normalizer->normalize($rawPayload);
    }
}

<?php

namespace App\Sources\Contracts;

use App\Sources\Data\Entity;
use App\Sources\Data\Finding;
use App\Sources\Data\ProviderResponse;
use App\Sources\Data\SourceConfig;
use App\Sources\Exceptions\PermanentSourceException;
use App\Sources\Exceptions\TransientSourceException;
use Illuminate\Support\Collection;

/**
 * Contrato único para os dois modos de fonte:
 *  - query-time: dispatch() faz HTTP;
 *  - dataset local: dispatch() faz um SELECT na versão ativa do dataset.
 */
interface RiskSourceProvider
{
    /**
     * @throws TransientSourceException vale tentar de novo (timeout, 5xx, 429)
     * @throws PermanentSourceException não adianta tentar de novo (4xx, sem chave)
     */
    public function dispatch(Entity $entity, SourceConfig $config): ProviderResponse;

    /**
     * @return Collection<int, Finding>
     */
    public function normalize(array $rawPayload): Collection;
}

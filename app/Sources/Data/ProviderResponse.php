<?php

namespace App\Sources\Data;

use Spatie\LaravelData\Data;

/**
 * O que o provider devolve ao job: o payload cru (vai para o object storage,
 * nunca para o Postgres) e metadados da chamada.
 *
 * $protocol != null indica fornecedor assíncrono: o resultado chegará depois,
 * via webhook. O job grava o protocolo, marca awaiting_callback e encerra (ack).
 */
class ProviderResponse extends Data
{
    public function __construct(
        public array $rawPayload,
        public int $costCents = 0,
        public ?string $protocol = null,
    ) {}
}

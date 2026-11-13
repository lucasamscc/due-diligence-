<?php

namespace App\Sources\Data;

use Spatie\LaravelData\Data;

/**
 * Uma fonte já resolvida: o que vai para o snapshot da diligência
 * e é copiado para cada DiligenceItem.
 */
class SourceConfig extends Data
{
    public function __construct(
        public string $provider,
        public string $product,
        public array $params = [],
    ) {}

    public function param(string $key, mixed $default = null): mixed
    {
        return data_get($this->params, $key, $default);
    }
}

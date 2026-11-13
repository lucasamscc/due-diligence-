<?php

namespace App\Sources\Data;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * Formato canônico de qualquer achado, de qualquer fornecedor.
 * Dado cadastral também vira Finding (type cadastral, severity info).
 */
class Finding extends Data
{
    public function __construct(
        public FindingType $type,
        public Severity $severity,
        public string $title,
        public ?CarbonImmutable $occurredAt = null,
        public array $details = [],
        public ?string $sourceReference = null,
    ) {}
}

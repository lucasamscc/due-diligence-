<?php

namespace App\Sources\Data;

use App\Diligence\Support\Cnpj;
use Spatie\LaravelData\Data;

/**
 * Entidade investigada. Não é Eloquent: é montada a partir de
 * diligences.entity_document no momento do dispatch.
 */
class Entity extends Data
{
    public function __construct(
        public string $document,
        public DocumentType $type,
    ) {}

    public static function fromDocument(string $document): self
    {
        $digits = Cnpj::strip($document);

        return new self(
            document: $digits,
            type: strlen($digits) === 11 ? DocumentType::Cpf : DocumentType::Cnpj,
        );
    }
}

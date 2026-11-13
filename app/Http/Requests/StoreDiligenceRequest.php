<?php

namespace App\Http\Requests;

use App\Diligence\Support\Cnpj;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreDiligenceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'document' => ['required', 'string', function (string $attr, mixed $value, Closure $fail) {
                if (! Cnpj::isValid((string) $value)) {
                    $fail('Informe um CNPJ válido (consulta por CPF não é suportada).');
                }
            }],
        ];
    }
}

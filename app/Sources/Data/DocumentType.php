<?php

namespace App\Sources\Data;

enum DocumentType: string
{
    case Cnpj = 'cnpj';
    // Modelado mas não consultável: não há fonte pública gratuita por CPF.
    case Cpf = 'cpf';
}

<?php

namespace App\Rules\Contracts;

use App\Rules\RiskContext;
use Closure;

/** Uma regra = uma classe no Pipeline. Lê findings, soma pontos, emite alertas. Nunca consulta fonte. */
interface RiskRule
{
    public function handle(RiskContext $context, Closure $next): RiskContext;
}

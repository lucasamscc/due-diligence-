<?php

namespace App\Rules\Rules;

use App\Diligence\Models\DiligenceItem;
use App\Diligence\States\Item\Succeeded;
use App\Rules\Contracts\RiskRule;
use App\Rules\RiskContext;
use Closure;

/**
 * Não soma pontos: score baixo com fonte faltando NÃO é "limpo".
 * O alerta deixa explícito o que não foi verificado.
 */
class IncompleteCoverageRule implements RiskRule
{
    public function handle(RiskContext $context, Closure $next): RiskContext
    {
        $missing = $context->items->reject(fn (DiligenceItem $i) => $i->status->equals(Succeeded::class));

        if ($missing->isNotEmpty()) {
            $context->alert(
                'incomplete_coverage',
                'warning',
                'Fontes não verificadas: '.$missing->map(fn (DiligenceItem $i) => $i->sourceKey())->implode(', ').'.',
            );
        }

        return $next($context);
    }
}

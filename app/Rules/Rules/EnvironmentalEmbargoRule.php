<?php

namespace App\Rules\Rules;

use App\Rules\Contracts\RiskRule;
use App\Rules\RiskContext;
use App\Sources\Data\FindingType;
use Closure;

class EnvironmentalEmbargoRule implements RiskRule
{
    public function handle(RiskContext $context, Closure $next): RiskContext
    {
        [$embargos, $past] = $context->risks(FindingType::Environmental)
            ->partition(fn ($f) => $f->details['active'] ?? true);

        if ($past->isNotEmpty()) {
            $context->alert('past_embargo', 'low', "{$past->count()} embargo(s) IBAMA cancelado(s) ou desembargado(s).", 5, $past->pluck('id')->all());
        }

        if ($embargos->isNotEmpty()) {
            $asOf = $embargos->first()->details['published_at'] ?? null;
            $context->alert(
                'environmental_embargo',
                'high',
                "{$embargos->count()} embargo(s) IBAMA".($asOf ? ' conforme lista publicada em '.substr($asOf, 0, 7) : '').'.',
                min(30 + 5 * ($embargos->count() - 1), 50),
                $embargos->pluck('id')->all(),
            );
        }

        return $next($context);
    }
}

<?php

namespace App\Rules\Rules;

use App\Diligence\Models\Finding;
use App\Rules\Contracts\RiskRule;
use App\Rules\RiskContext;
use App\Sources\Data\FindingType;
use Closure;

class SanctionRule implements RiskRule
{
    public function handle(RiskContext $context, Closure $next): RiskContext
    {
        $sanctions = $context->risks(FindingType::Sanction);
        [$active, $past] = $sanctions->partition(fn (Finding $f) => $f->details['active'] ?? true);

        if ($active->isNotEmpty()) {
            $lists = $active->pluck('details.product')->unique()->map('strtoupper')->implode(', ');
            $context->alert('active_sanction', 'critical', "{$active->count()} sanção(ões) vigente(s): {$lists}.", 60, $active->pluck('id')->all());
        }

        if ($past->isNotEmpty()) {
            $context->alert('past_sanction', 'medium', "{$past->count()} sanção(ões) já encerrada(s).", 15, $past->pluck('id')->all());
        }

        return $next($context);
    }
}

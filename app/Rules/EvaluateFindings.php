<?php

namespace App\Rules;

use App\Diligence\Models\Diligence;
use App\Diligence\States\Item\Succeeded;
use App\Rules\Models\RiskAssessment;
use App\Rules\Rules\EnvironmentalEmbargoRule;
use App\Rules\Rules\IncompleteCoverageRule;
use App\Rules\Rules\RegistrationStatusRule;
use App\Rules\Rules\SanctionRule;
use Illuminate\Pipeline\Pipeline;

/**
 * Motor de regras: só lê findings já gravados. Pode rodar de novo a qualquer
 * momento (diligence:reevaluate) com regras novas, sem reconsultar fornecedor.
 */
class EvaluateFindings
{
    /** Suba quando mudar regra/peso: cada avaliação registra com qual versão foi feita. */
    public const RULES_VERSION = '2026.11.1';

    private const RULES = [
        RegistrationStatusRule::class,
        SanctionRule::class,
        EnvironmentalEmbargoRule::class,
        IncompleteCoverageRule::class,
    ];

    public function __construct(private Pipeline $pipeline) {}

    public function handle(Diligence $diligence): RiskAssessment
    {
        $items = $diligence->items()->with('findings')->get();

        /** @var RiskContext $context */
        $context = $this->pipeline
            ->send(new RiskContext($diligence, $items->flatMap->findings, $items))
            ->through(self::RULES)
            ->thenReturn();

        $score = min($context->score, 100);

        return $diligence->assessments()->create([
            'score' => $score,
            'level' => match (true) {
                $score >= 60 => 'high',
                $score >= 25 => 'medium',
                default => 'low',
            },
            'is_complete' => $items->every(fn ($i) => $i->status->equals(Succeeded::class)),
            'alerts' => $context->alerts,
            'rules_version' => self::RULES_VERSION,
            'evaluated_at' => now(),
        ]);
    }
}

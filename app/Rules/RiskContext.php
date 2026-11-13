<?php

namespace App\Rules;

use App\Diligence\Models\Diligence;
use App\Diligence\Models\DiligenceItem;
use App\Diligence\Models\Finding;
use App\Sources\Data\FindingType;
use Illuminate\Support\Collection;

class RiskContext
{
    public int $score = 0;

    /** @var list<array{code: string, severity: string, message: string, points: int, finding_ids: list<int>}> */
    public array $alerts = [];

    /**
     * @param  Collection<int, Finding>  $findings
     * @param  Collection<int, DiligenceItem>  $items
     */
    public function __construct(
        public readonly Diligence $diligence,
        public readonly Collection $findings,
        public readonly Collection $items,
    ) {}

    /** @return Collection<int, Finding> só achados que representam risco (severity != info) */
    public function risks(FindingType $type): Collection
    {
        return $this->findings->filter(fn (Finding $f) => $f->type === $type && $f->severity->isRisk());
    }

    public function alert(string $code, string $severity, string $message, int $points = 0, array $findingIds = []): void
    {
        $this->score += $points;
        $this->alerts[] = compact('code', 'severity', 'message', 'points') + ['finding_ids' => array_values($findingIds)];
    }
}

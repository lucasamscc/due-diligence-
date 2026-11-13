<?php

namespace App\Http\Resources;

use App\Diligence\Support\Cnpj;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Só dado normalizado sai daqui: payload cru de fornecedor nunca chega ao front. */
class DiligenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document' => Cnpj::format($this->entity_document),
            'status' => (string) $this->status,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'risk' => $this->whenLoaded('latestAssessment', fn () => $this->latestAssessment ? [
                'score' => $this->latestAssessment->score,
                'level' => $this->latestAssessment->level,
                'is_complete' => $this->latestAssessment->is_complete,
                'alerts' => $this->latestAssessment->alerts,
                'rules_version' => $this->latestAssessment->rules_version,
                'evaluated_at' => $this->latestAssessment->evaluated_at,
            ] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'source' => $i->sourceKey(),
                'status' => (string) $i->status,
                'attempts' => $i->attempts,
                'error' => $i->error_message,
                'finished_at' => $i->finished_at,
                'findings' => $i->findings->map(fn ($f) => [
                    'type' => $f->type,
                    'severity' => $f->severity,
                    'title' => $f->title,
                    'occurred_at' => $f->occurred_at,
                    'details' => $f->details,
                    'source_reference' => $f->source_reference,
                ]),
            ])),
        ];
    }
}

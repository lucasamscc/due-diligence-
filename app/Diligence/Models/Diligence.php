<?php

namespace App\Diligence\Models;

use App\Diligence\States\Diligence\DiligenceState;
use App\Rules\Models\RiskAssessment;
use App\Sources\Data\Entity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\ModelStates\HasStates;

/**
 * @property int $id
 * @property string $entity_document
 * @property array $config_snapshot
 * @property DiligenceState $status
 */
class Diligence extends Model
{
    use HasStates;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'config_snapshot' => 'array',
            'status' => DiligenceState::class,
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(DiligenceItem::class);
    }

    public function findings(): HasManyThrough
    {
        return $this->hasManyThrough(Finding::class, DiligenceItem::class);
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(RiskAssessment::class);
    }

    public function latestAssessment(): HasOne
    {
        return $this->hasOne(RiskAssessment::class)->latestOfMany('evaluated_at');
    }

    public function entity(): Entity
    {
        return Entity::fromDocument($this->entity_document);
    }
}

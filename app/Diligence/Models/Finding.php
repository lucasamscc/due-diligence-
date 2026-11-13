<?php

namespace App\Diligence\Models;

use App\Sources\Data\FindingType;
use App\Sources\Data\Severity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property FindingType $type
 * @property Severity $severity
 * @property array $details
 */
class Finding extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => FindingType::class,
            'severity' => Severity::class,
            'details' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(DiligenceItem::class, 'diligence_item_id');
    }
}

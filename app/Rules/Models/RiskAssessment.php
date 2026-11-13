<?php

namespace App\Rules\Models;

use App\Diligence\Models\Diligence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskAssessment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'alerts' => 'array',
            'is_complete' => 'boolean',
            'evaluated_at' => 'immutable_datetime',
        ];
    }

    public function diligence(): BelongsTo
    {
        return $this->belongsTo(Diligence::class);
    }
}

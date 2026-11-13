<?php

namespace App\Datasets\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DatasetVersion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'published_at' => 'immutable_datetime',
            'ingested_at' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }

    public function records(): HasMany
    {
        return $this->hasMany(DatasetRecord::class);
    }

    public function scopeActiveFor(Builder $query, string $source): Builder
    {
        return $query->where('source', $source)->where('is_active', true);
    }
}

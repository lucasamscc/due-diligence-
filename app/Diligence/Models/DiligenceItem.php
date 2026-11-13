<?php

namespace App\Diligence\Models;

use App\Diligence\States\Item\ItemState;
use App\Sources\Data\SourceConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\ModelStates\HasStates;

/**
 * Unidade atômica de retry, estado, custo e resultado.
 * provider/product/params são cópia do snapshot — nunca ponteiro para o registry.
 *
 * @property int $id
 * @property int $diligence_id
 * @property string $provider
 * @property string $product
 * @property array $params
 * @property ItemState $status
 * @property int $attempts
 */
class DiligenceItem extends Model
{
    use HasStates;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'status' => ItemState::class,
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function diligence(): BelongsTo
    {
        return $this->belongsTo(Diligence::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    public function sourceConfig(): SourceConfig
    {
        return new SourceConfig($this->provider, $this->product, $this->params ?? []);
    }

    public function sourceKey(): string
    {
        return "{$this->provider}.{$this->product}";
    }
}

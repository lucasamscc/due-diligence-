<?php

namespace App\Diligence\Actions;

use App\Diligence\Jobs\QuerySourceJob;
use App\Diligence\Models\DiligenceItem;
use App\Sources\SourceRegistry;

/** Publica o job do item na connection do fornecedor (exchange topic → fila do fornecedor). */
class DispatchItem
{
    public function __construct(private SourceRegistry $registry) {}

    public function handle(DiligenceItem $item): void
    {
        QuerySourceJob::dispatch($item->id, $item->diligence_id, $item->provider, $item->product)
            ->onConnection($this->registry->connectionFor($item->provider));
    }
}

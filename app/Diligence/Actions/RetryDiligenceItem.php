<?php

namespace App\Diligence\Actions;

use App\Diligence\Models\DiligenceItem;
use App\Diligence\States\Diligence\Running;
use App\Diligence\States\Item\Failed;
use App\Diligence\States\Item\Pending;
use App\Diligence\States\Item\TimedOut;
use App\Diligence\Support\FanIn;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Reprocessa só uma fonte que falhou, sem reconsultar (nem repagar) as outras. */
class RetryDiligenceItem
{
    public function __construct(private FanIn $fanIn, private DispatchItem $dispatchItem) {}

    public function handle(DiligenceItem $item): DiligenceItem
    {
        $item = DB::transaction(function () use ($item) {
            $item = DiligenceItem::lockForUpdate()->findOrFail($item->id);

            if (! $item->status->equals(Failed::class, TimedOut::class)) {
                throw new InvalidArgumentException("Item {$item->id} está em {$item->status}; só failed/timed_out podem ser reprocessados.");
            }

            $item->status->transitionTo(Pending::class);
            $item->update(['attempts' => 0, 'error_message' => null, 'started_at' => null, 'finished_at' => null]);

            $diligence = $item->diligence()->lockForUpdate()->first();
            if ($diligence->status->isFinal()) {
                $diligence->status->transitionTo(Running::class);
                $diligence->update(['finished_at' => null]);
            }

            return $item;
        });

        $this->fanIn->add($item->diligence_id);
        $this->dispatchItem->handle($item);

        return $item->refresh();
    }
}

<?php

namespace App\Diligence\Actions;

use App\Diligence\Models\DiligenceItem;
use App\Diligence\States\Item\Failed;
use Throwable;

class FailDiligenceItem
{
    /** @return bool false se o item já estava final (falha duplicada) */
    public function handle(DiligenceItem $item, Throwable $e): bool
    {
        if ($item->status->isFinal()) {
            return false;
        }

        $item->status->transitionTo(Failed::class);
        $item->update([
            'error_message' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 2000),
            'finished_at' => now(),
        ]);

        return true;
    }
}

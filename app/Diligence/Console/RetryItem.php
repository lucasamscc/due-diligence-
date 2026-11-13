<?php

namespace App\Diligence\Console;

use App\Diligence\Actions\RetryDiligenceItem;
use App\Diligence\Models\DiligenceItem;
use Illuminate\Console\Command;

class RetryItem extends Command
{
    protected $signature = 'diligence:retry-item {item}';

    protected $description = 'Reprocessa só uma fonte que falhou';

    public function handle(RetryDiligenceItem $retry): int
    {
        $item = $retry->handle(DiligenceItem::findOrFail($this->argument('item')));
        $this->info("Item {$item->id} ({$item->sourceKey()}) republicado.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Diligence\Console;

use App\Diligence\Actions\CreateDiligence;
use Illuminate\Console\Command;

class RunDiligence extends Command
{
    protected $signature = 'diligence:run {cnpj}';

    protected $description = 'Cria uma diligência e publica um job por fonte';

    public function handle(CreateDiligence $create): int
    {
        $diligence = $create->handle($this->argument('cnpj'));

        $this->info("Diligência {$diligence->id} criada; {$diligence->items->count()} jobs publicados:");
        foreach ($diligence->items as $item) {
            $this->line("  #{$item->id} {$item->sourceKey()}");
        }
        $this->line("Acompanhe: php artisan diligence:show {$diligence->id}");

        return self::SUCCESS;
    }
}

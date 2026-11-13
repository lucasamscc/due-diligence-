<?php

namespace App\Diligence\Console;

use App\Diligence\Jobs\FinalizeDiligenceJob;
use App\Diligence\Models\Diligence;
use Illuminate\Console\Command;

/** Se o Redis perdeu o contador de fan-in, o Postgres ainda sabe a verdade. */
class Reconcile extends Command
{
    protected $signature = 'diligence:reconcile';

    protected $description = 'Dispara o finalize de diligências running cujos itens já estão todos finais';

    public function handle(): int
    {
        $stuck = Diligence::query()
            ->where('status', 'running')
            ->whereDoesntHave('items', fn ($q) => $q->whereIn('status', ['pending', 'running', 'awaiting_callback']))
            ->pluck('id');

        foreach ($stuck as $id) {
            FinalizeDiligenceJob::dispatch($id)->onConnection('rabbitmq_control');
        }

        $this->info("{$stuck->count()} diligência(s) reenviada(s) ao finalize.");

        return self::SUCCESS;
    }
}

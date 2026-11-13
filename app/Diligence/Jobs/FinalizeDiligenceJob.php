<?php

namespace App\Diligence\Jobs;

use App\Diligence\Actions\FinalizeDiligence;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Fan-in + motor de regras. Vai para a fila "diligence" (routing key control.diligence). */
class FinalizeDiligenceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(public int $diligenceId) {}

    public function handle(FinalizeDiligence $finalize): void
    {
        $finalize->handle($this->diligenceId);
    }
}

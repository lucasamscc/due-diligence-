<?php

namespace App\Diligence\Console;

use App\Diligence\Models\Diligence;
use App\Rules\EvaluateFindings;
use Illuminate\Console\Command;

class Reevaluate extends Command
{
    protected $signature = 'diligence:reevaluate {id?} {--all : Todas as diligências finalizadas}';

    protected $description = 'Roda o motor de regras de novo sobre findings já gravados (não reconsulta fontes)';

    public function handle(EvaluateFindings $evaluate): int
    {
        $query = Diligence::query()->whereIn('status', ['completed', 'completed_with_errors', 'failed']);
        $query = $this->option('all') ? $query : $query->whereKey($this->argument('id'));

        $query->lazyById()->each(function (Diligence $d) use ($evaluate) {
            $a = $evaluate->handle($d);
            $this->line("#{$d->id}: {$a->level} ({$a->score}) regras {$a->rules_version}");
        });

        return self::SUCCESS;
    }
}

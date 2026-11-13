<?php

namespace App\Diligence\Console;

use App\Diligence\Models\Diligence;
use App\Diligence\Support\Cnpj;
use App\Diligence\Support\FanIn;
use Illuminate\Console\Command;

class ShowDiligence extends Command
{
    protected $signature = 'diligence:show {id}';

    protected $description = 'Mostra estado, itens, findings e avaliação de risco de uma diligência';

    public function handle(FanIn $fanIn): int
    {
        $d = Diligence::with(['items.findings', 'latestAssessment'])->findOrFail($this->argument('id'));

        $this->info(sprintf('Diligência %d — %s — %s (fan-in pendente no Redis: %s)',
            $d->id, Cnpj::format($d->entity_document), $d->status, $fanIn->pending($d->id) ?? '—'));

        $this->table(['#', 'fonte', 'estado', 'tent.', 'findings', 'erro'], $d->items->map(fn ($i) => [
            $i->id, $i->sourceKey(), $i->status, $i->attempts, $i->findings->count(), str($i->error_message)->limit(70),
        ]));

        foreach ($d->items->flatMap->findings as $f) {
            $this->line(sprintf('  [%s/%s] %s', $f->type->value, $f->severity->value, $f->title));
        }

        if ($a = $d->latestAssessment) {
            $this->newLine();
            $this->info("Risco: {$a->level} (score {$a->score}) — ".($a->is_complete ? 'cobertura completa' : 'COBERTURA INCOMPLETA')." — regras {$a->rules_version}");
            foreach ($a->alerts as $alert) {
                $this->line("  ! [{$alert['severity']}] {$alert['message']}");
            }
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Diligence\Actions;

use App\Diligence\Models\Diligence;
use App\Diligence\States\Diligence\Completed;
use App\Diligence\States\Diligence\CompletedWithErrors;
use App\Diligence\States\Diligence\Failed;
use App\Diligence\States\Item\Succeeded;
use App\Rules\EvaluateFindings;
use Illuminate\Support\Facades\DB;

/**
 * Fan-in: fecha a diligência quando TODOS os itens estão em estado final.
 * O contador Redis só diz "talvez acabou"; quem decide é o Postgres, com lock.
 * Falha parcial é resultado válido: completed_with_errors, não failed.
 */
class FinalizeDiligence
{
    public function __construct(private EvaluateFindings $evaluate) {}

    /** @return bool true se fechou agora */
    public function handle(int $diligenceId): bool
    {
        $closed = DB::transaction(function () use ($diligenceId) {
            $diligence = Diligence::lockForUpdate()->findOrFail($diligenceId);

            if ($diligence->status->isFinal()) {
                return null;
            }

            $items = $diligence->items()->get();
            if ($items->contains(fn ($i) => ! $i->status->isFinal())) {
                return null;
            }

            $succeeded = $items->filter(fn ($i) => $i->status->equals(Succeeded::class))->count();
            $target = match (true) {
                $succeeded === $items->count() => Completed::class,
                $succeeded > 0 => CompletedWithErrors::class,
                default => Failed::class,
            };

            $diligence->status->transitionTo($target);
            $diligence->update(['finished_at' => now()]);

            return $diligence;
        });

        if ($closed === null) {
            return false;
        }

        // Passo próprio, fora da transação de coleta: regras não reconsultam nada.
        $this->evaluate->handle($closed);

        return true;
    }
}

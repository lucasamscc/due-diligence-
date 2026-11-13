<?php

namespace App\Datasets\Actions;

use App\Datasets\Models\DatasetVersion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Swap atômico: valida a versão nova e a torna ativa numa transação.
 * Nunca há instante sem versão ativa — quem consulta vê a antiga até o COMMIT
 * e a nova logo depois. Nada de TRUNCATE + INSERT.
 */
class PublishDatasetVersion
{
    public function handle(DatasetVersion $version, int $minRows, float $maxShrinkRatio): DatasetVersion
    {
        $current = DatasetVersion::activeFor($version->source)->first();

        $reason = match (true) {
            $version->row_count < $minRows => "contagem implausível: {$version->row_count} < mínimo {$minRows}",
            $current !== null && $version->row_count < $current->row_count * $maxShrinkRatio => sprintf(
                'diff absurdo: %d linhas contra %d na versão ativa (queda > %d%%)',
                $version->row_count, $current->row_count, (int) ((1 - $maxShrinkRatio) * 100),
            ),
            default => null,
        };

        if ($reason !== null) {
            $version->update(['status' => 'rejected', 'rejection_reason' => $reason]);

            throw new RuntimeException("Versão {$version->id} rejeitada — {$reason}. A versão ativa foi mantida.");
        }

        DB::transaction(function () use ($version, $current) {
            // Ordem importa: o índice único parcial (source) WHERE is_active
            // proíbe duas ativas, então desativa antes de ativar — dentro da mesma transação.
            $current?->update(['is_active' => false]);
            $version->update(['is_active' => true, 'status' => 'published', 'ingested_at' => now()]);
        });

        return $version->refresh();
    }
}

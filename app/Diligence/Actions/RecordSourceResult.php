<?php

namespace App\Diligence\Actions;

use App\Diligence\Models\DiligenceItem;
use App\Diligence\States\Item\Succeeded;
use App\Sources\Contracts\RiskSourceProvider;
use App\Sources\Data\Finding;
use App\Sources\Data\ProviderResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Persiste o resultado de uma fonte: payload cru no object storage (chave + hash no
 * Postgres), findings normalizados no Postgres, item em succeeded.
 */
class RecordSourceResult
{
    public function handle(DiligenceItem $item, RiskSourceProvider $provider, ProviderResponse $response, bool $fromCache = false): void
    {
        $json = json_encode($response->rawPayload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $key = sprintf('raw/%d/%d-%s.json', $item->diligence_id, $item->id, $item->sourceKey());
        Storage::disk(config('sources.raw_disk'))->put($key, $json);

        // Normaliza fora da transação: se o normalizer tiver bug, nada foi gravado pela metade.
        $findings = $provider->normalize($response->rawPayload);

        DB::transaction(function () use ($item, $findings, $key, $json, $response, $fromCache) {
            // Idempotência: mensagem reentregue (worker morreu antes do ack) não duplica findings.
            $item->findings()->delete();

            $item->findings()->createMany($findings->map(fn (Finding $f) => [
                'type' => $f->type,
                'severity' => $f->severity,
                'title' => mb_substr($f->title, 0, 255),
                'occurred_at' => $f->occurredAt,
                'details' => $f->details + ($fromCache ? ['served_from_cache' => true] : []),
                'source_reference' => $f->sourceReference,
            ])->all());

            $item->status->transitionTo(Succeeded::class);
            $item->update([
                'raw_payload_key' => $key,
                'raw_payload_hash' => hash('sha256', $json),
                'cost_cents' => $fromCache ? 0 : $response->costCents,
                'finished_at' => now(),
                'error_message' => null,
            ]);
        });
    }
}

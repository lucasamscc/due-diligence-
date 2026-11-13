<?php

namespace App\Sources\Ibama;

use App\Sources\Data\Finding;
use App\Sources\Data\FindingType;
use App\Sources\Data\Severity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

class IbamaNormalizer
{
    /**
     * @param  array{dataset_version_id: int, published_at: ?string, ingested_at: ?string, records: list<array>}  $raw
     * @return Collection<int, Finding>
     */
    public function normalize(array $raw): Collection
    {
        // Todo achado diz de QUAL cópia da lista veio: "limpo" só vale com data.
        $provenance = [
            'dataset' => 'ibama_embargos',
            'dataset_version_id' => $raw['dataset_version_id'],
            'published_at' => $raw['published_at'],
            'ingested_at' => $raw['ingested_at'],
        ];
        $asOf = $raw['published_at'] ? CarbonImmutable::parse($raw['published_at'])->format('m/Y') : 'data desconhecida';

        if ($raw['records'] === []) {
            return collect([new Finding(
                type: FindingType::Environmental,
                severity: Severity::Info,
                title: "Nenhum embargo IBAMA (lista publicada em {$asOf})",
                details: $provenance + ['matches' => 0],
                sourceReference: "ibama:embargos:v{$raw['dataset_version_id']}",
            )]);
        }

        return collect($raw['records'])->map(fn (array $r) => $this->embargo($r, $provenance, $asOf));
    }

    private function embargo(array $r, array $provenance, string $asOf): Finding
    {
        $active = ! ($r['cancelled'] ?? false) && blank($r['lifted_at'] ?? null);

        return new Finding(
            type: FindingType::Environmental,
            severity: $active ? Severity::High : Severity::Low,
            title: ($active ? 'Embargo IBAMA' : 'Embargo IBAMA (encerrado)').(filled($r['key'] ?? null) ? " nº {$r['key']}" : '')
                .(filled($r['municipality'] ?? null) ? " — {$r['municipality']}/{$r['uf']}" : '')
                ." (lista publicada em {$asOf})",
            occurredAt: $this->date($r['date'] ?? null),
            details: $provenance + [
                'active' => $active,
                'cancelled' => $r['cancelled'] ?? false,
                'lifted_at' => $r['lifted_at'] ?? null,
                'process' => $r['process'] ?? null,
                'name' => $r['name'] ?? null,
                'description' => $r['description'] ?? null,
                'municipality' => $r['municipality'] ?? null,
                'uf' => $r['uf'] ?? null,
                'embargo_date' => $r['date'] ?? null,
            ],
            sourceReference: 'ibama:embargos:'.($r['key'] ?? ''),
        );
    }

    private function date(?string $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        try {
            return str_contains($value, '/')
                ? CarbonImmutable::createFromFormat('!d/m/Y', substr($value, 0, 10)) ?: null
                : CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}

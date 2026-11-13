<?php

namespace App\Sources\Transparencia;

use App\Sources\Data\Finding;
use App\Sources\Data\FindingType;
use App\Sources\Data\Severity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class TransparenciaNormalizer
{
    private const LABELS = [
        'ceis' => 'CEIS — Cadastro de Empresas Inidôneas e Suspensas',
        'cnep' => 'CNEP — Cadastro Nacional de Empresas Punidas',
        'cepim' => 'CEPIM — Entidades Privadas sem Fins Lucrativos Impedidas',
    ];

    /**
     * @param  array{product: string, records: list<array>}  $raw
     * @return Collection<int, Finding>
     */
    public function normalize(array $raw): Collection
    {
        $product = $raw['product'];
        $label = self::LABELS[$product] ?? strtoupper($product);
        $records = $raw['records'] ?? [];

        // "Nada encontrado" também é um achado: registra QUE a lista foi consultada.
        // Sem isso, não se distingue "limpo" de "fonte não consultada".
        if ($records === []) {
            return collect([new Finding(
                type: FindingType::Sanction,
                severity: Severity::Info,
                title: "Nenhum registro no {$label}",
                details: ['product' => $product, 'matches' => 0],
                sourceReference: "transparencia:{$product}",
            )]);
        }

        return collect($records)->map(fn (array $r) => $product === 'cepim'
            ? $this->cepim($r, $label)
            : $this->sanction($product, $r, $label));
    }

    private function sanction(string $product, array $r, string $label): Finding
    {
        $end = $this->date(data_get($r, 'dataFimSancao'));
        $active = $end === null || $end->isFuture();
        $type = data_get($r, 'tipoSancao.descricaoResumida') ?? data_get($r, 'tipoSancao.descricaoPortal') ?? 'Sanção';

        return new Finding(
            type: FindingType::Sanction,
            severity: $active ? Severity::High : Severity::Low,
            title: strtoupper($product).": {$type} — ".(data_get($r, 'orgaoSancionador.nome') ?? 'órgão não informado'),
            occurredAt: $this->date(data_get($r, 'dataInicioSancao')),
            details: [
                'product' => $product,
                'list' => $label,
                'active' => $active,
                'sanction_type' => $type,
                'start' => data_get($r, 'dataInicioSancao'),
                'end' => data_get($r, 'dataFimSancao'),
                'agency' => data_get($r, 'orgaoSancionador.nome'),
                'agency_uf' => data_get($r, 'orgaoSancionador.siglaUf'),
                'process' => data_get($r, 'numeroProcesso'),
                'legal_basis' => collect(data_get($r, 'fundamentacao', []))->pluck('descricao')->filter()->values()->all(),
                'fine' => data_get($r, 'valorMulta'),
                'sanctioned_name' => data_get($r, 'sancionado.nome') ?? data_get($r, 'pessoa.nome'),
                'publication' => data_get($r, 'linkPublicacao'),
            ],
            sourceReference: "transparencia:{$product}:".data_get($r, 'id'),
        );
    }

    private function cepim(array $r, string $label): Finding
    {
        return new Finding(
            type: FindingType::Sanction,
            severity: Severity::Medium,
            title: 'CEPIM: impedida de celebrar convênios — '.(data_get($r, 'orgaoSuperior.nome') ?? 'órgão não informado'),
            occurredAt: $this->date(data_get($r, 'dataReferencia')),
            details: [
                'product' => 'cepim',
                'list' => $label,
                'active' => true,
                'reason' => data_get($r, 'motivo'),
                'agency' => data_get($r, 'orgaoSuperior.nome'),
                'agreement' => data_get($r, 'convenio.codigo') ?? data_get($r, 'convenio.numero'),
                'agreement_object' => data_get($r, 'convenio.objeto'),
            ],
            sourceReference: 'transparencia:cepim:'.data_get($r, 'id'),
        );
    }

    /** A API devolve dd/mm/aaaa. */
    private function date(?string $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        return str_contains($value, '/')
            ? CarbonImmutable::createFromFormat('!d/m/Y', $value) ?: null
            : CarbonImmutable::parse($value);
    }
}

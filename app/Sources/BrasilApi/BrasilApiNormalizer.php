<?php

namespace App\Sources\BrasilApi;

use App\Sources\Data\Finding;
use App\Sources\Data\FindingType;
use App\Sources\Data\Severity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Anti-corruption layer: payload da BrasilAPI → Finding canônico.
 * Não julga risco (isso é do motor de regras): só traduz.
 */
class BrasilApiNormalizer
{
    /**
     * @param  array{status: int, body: array}  $raw
     * @return Collection<int, Finding>
     */
    public function normalize(array $raw): Collection
    {
        $body = $raw['body'] ?? [];

        if (($raw['status'] ?? 200) === 404) {
            return collect([new Finding(
                type: FindingType::Cadastral,
                severity: Severity::Info,
                title: 'CNPJ não encontrado na base da Receita Federal',
                details: ['found' => false, 'message' => $body['message'] ?? null],
                sourceReference: 'brasilapi:cnpj',
            )]);
        }

        return collect([new Finding(
            type: FindingType::Cadastral,
            severity: Severity::Info,
            title: 'Dados cadastrais: '.($body['razao_social'] ?? 'sem razão social'),
            occurredAt: $this->date($body['data_inicio_atividade'] ?? null),
            details: [
                'found' => true,
                'cnpj' => $body['cnpj'] ?? null,
                'razao_social' => $body['razao_social'] ?? null,
                'nome_fantasia' => ($body['nome_fantasia'] ?? '') ?: null,
                'situacao_cadastral' => isset($body['descricao_situacao_cadastral'])
                    ? mb_strtoupper($body['descricao_situacao_cadastral'])
                    : null,
                'data_situacao_cadastral' => $body['data_situacao_cadastral'] ?? null,
                'motivo_situacao_cadastral' => $body['descricao_motivo_situacao_cadastral'] ?? null,
                'data_inicio_atividade' => $body['data_inicio_atividade'] ?? null,
                'natureza_juridica' => $body['natureza_juridica'] ?? null,
                'porte' => $body['porte'] ?? null,
                'capital_social' => $body['capital_social'] ?? null,
                'cnae_principal' => [
                    'codigo' => $body['cnae_fiscal'] ?? null,
                    'descricao' => $body['cnae_fiscal_descricao'] ?? null,
                ],
                'municipio' => $body['municipio'] ?? null,
                'uf' => $body['uf'] ?? null,
                'email' => $body['email'] ?? null,
                'telefone' => $body['ddd_telefone_1'] ?? null,
                'socios' => collect($body['qsa'] ?? [])->map(fn (array $s) => [
                    'nome' => $s['nome_socio'] ?? null,
                    'qualificacao' => $s['qualificacao_socio'] ?? null,
                    'entrada' => $s['data_entrada_sociedade'] ?? null,
                ])->values()->all(),
            ],
            sourceReference: 'brasilapi:cnpj:'.($body['cnpj'] ?? ''),
        )]);
    }

    private function date(?string $value): ?CarbonImmutable
    {
        return $value ? CarbonImmutable::parse($value) : null;
    }
}

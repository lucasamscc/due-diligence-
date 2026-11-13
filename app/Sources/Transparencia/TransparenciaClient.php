<?php

namespace App\Sources\Transparencia;

use App\Sources\Exceptions\PermanentSourceException;
use App\Sources\HttpErrors;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Portal da Transparência — CEIS, CNEP, CEPIM.
 * Auth via header "chave-api-dados" (chave grátis em portaldatransparencia.gov.br/api-de-dados/cadastrar-email).
 */
class TransparenciaClient
{
    /** Filtro por CNPJ de cada cadastro (nomes do Swagger oficial). */
    private const FILTERS = [
        'ceis' => 'codigoSancionado',
        'cnep' => 'codigoSancionado',
        'cepim' => 'cnpjSancionado',
    ];

    /**
     * Busca todas as páginas (a API pagina em blocos de 15 e devolve [] no fim).
     *
     * @return list<array>
     */
    public function sanctions(string $product, string $cnpj): array
    {
        $key = config('sources.transparencia.api_key');
        if (blank($key)) {
            throw new PermanentSourceException('TRANSPARENCIA_API_KEY não configurada.');
        }

        $filter = self::FILTERS[$product] ?? throw new PermanentSourceException("Produto desconhecido: {$product}");
        $records = [];

        for ($page = 1; $page <= config('sources.transparencia.max_pages'); $page++) {
            try {
                $response = Http::baseUrl(config('sources.transparencia.base_url'))
                    ->timeout(config('sources.transparencia.timeout'))
                    ->acceptJson()
                    ->withHeaders(['chave-api-dados' => $key])
                    ->get("/{$product}", [$filter => $cnpj, 'pagina' => $page]);
            } catch (ConnectionException $e) {
                HttpErrors::fromConnection('Portal da Transparência', $e);
            }

            if (! $response->successful()) {
                HttpErrors::fromResponse('Portal da Transparência', $response);
            }

            $batch = $response->json() ?? [];
            if ($batch === []) {
                break;
            }

            array_push($records, ...$batch);
        }

        return $records;
    }
}

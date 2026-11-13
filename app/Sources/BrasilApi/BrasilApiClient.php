<?php

namespace App\Sources\BrasilApi;

use App\Sources\HttpErrors;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Só HTTP: URL, timeout, mapeamento de erro de transporte. */
class BrasilApiClient
{
    /**
     * @return array{status: int, body: array}
     */
    public function cnpj(string $cnpj): array
    {
        try {
            // Sem Http::retry(): quem decide retry é a fila (backoff via delay queue + TTL).
            // Retry dentro do job prende o worker e esconde a falha da Management UI.
            $response = Http::baseUrl(config('sources.brasilapi.base_url'))
                ->timeout(config('sources.brasilapi.timeout'))
                ->acceptJson()
                ->get("/cnpj/v1/{$cnpj}");
        } catch (ConnectionException $e) {
            HttpErrors::fromConnection('BrasilAPI', $e);
        }

        // 404 não é erro de integração: é a resposta "esse CNPJ não existe na base da Receita".
        if ($response->successful() || $response->status() === 404) {
            return ['status' => $response->status(), 'body' => $response->json() ?? []];
        }

        HttpErrors::fromResponse('BrasilAPI', $response);
    }
}

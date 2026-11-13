<?php

namespace App\Sources;

use App\Sources\Exceptions\PermanentSourceException;
use App\Sources\Exceptions\TransientSourceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * Tradução comum de falha HTTP para as duas categorias que o job entende.
 * A decisão "vale tentar de novo?" é tomada aqui, uma vez, e não espalhada nos jobs.
 */
final class HttpErrors
{
    public static function fromResponse(string $provider, Response $response): never
    {
        $status = $response->status();
        $message = "{$provider} respondeu HTTP {$status}";

        if ($status === 429 || $status === 408 || $status >= 500) {
            throw new TransientSourceException($message);
        }

        throw new PermanentSourceException($message.': '.mb_substr($response->body(), 0, 300));
    }

    public static function fromConnection(string $provider, ConnectionException $e): never
    {
        throw new TransientSourceException("{$provider} inacessível: {$e->getMessage()}", previous: $e);
    }
}

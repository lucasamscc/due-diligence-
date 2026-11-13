<?php

namespace Tests\Feature;

use App\Sources\BrasilApi\BrasilApiProvider;
use App\Sources\Data\Entity;
use App\Sources\Data\SourceConfig;
use App\Sources\Exceptions\PermanentSourceException;
use App\Sources\Exceptions\TransientSourceException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BrasilApiProviderTest extends TestCase
{
    private function consult(): array
    {
        return app(BrasilApiProvider::class)
            ->dispatch(Entity::fromDocument('33000167000101'), new SourceConfig('brasilapi', 'cnpj'))
            ->rawPayload;
    }

    public function test_success(): void
    {
        Http::fake(['brasilapi.com.br/api/cnpj/v1/33000167000101' => Http::response(['razao_social' => 'PETROBRAS'])]);

        $this->assertSame(['status' => 200, 'body' => ['razao_social' => 'PETROBRAS']], $this->consult());
    }

    public function test_404_is_a_valid_answer(): void
    {
        Http::fake(['*' => Http::response(['message' => 'não encontrado'], 404)]);

        $this->assertSame(404, $this->consult()['status']);
    }

    public function test_5xx_and_429_are_transient(): void
    {
        Http::fake(['*' => Http::sequence()->push('', 503)->push('', 429)]);

        foreach ([1, 2] as $_) {
            try {
                $this->consult();
                $this->fail('esperava exceção');
            } catch (TransientSourceException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_other_4xx_is_permanent(): void
    {
        Http::fake(['*' => Http::response('bad', 400)]);

        $this->expectException(PermanentSourceException::class);
        $this->consult();
    }
}

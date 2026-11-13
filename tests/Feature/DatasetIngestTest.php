<?php

namespace Tests\Feature;

use App\Datasets\IbamaEmbargosIngestor;
use App\Datasets\Models\DatasetVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DatasetIngestTest extends TestCase
{
    use RefreshDatabase;

    private function csv(int $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ibama').'.csv';
        $lines = ['SEQ_TAD;CPF_CNPJ_EMBARGADO;NOME_EMBARGADO;DAT_EMBARGO;MUNICIPIO;UF;DES_TAD'];
        for ($i = 1; $i <= $rows; $i++) {
            $lines[] = "{$i};11.222.333/0001-81;Empresa {$i} Ltda;01/01/2024;Altamira;PA;Embargo {$i}";
        }
        file_put_contents($path, implode("\n", $lines));

        return $path;
    }

    public function test_ingests_fixture_and_activates_version(): void
    {
        $version = app(IbamaEmbargosIngestor::class)->ingest(base_path('tests/Fixtures/ibama_embargos.csv'));

        $this->assertTrue($version->is_active);
        $this->assertSame(4, $version->row_count);
        $this->assertNotNull($version->ingested_at);
        $this->assertSame(2, $version->records()->where('document', '11222333000181')->count());
        // CPF mascarado não vira documento.
        $this->assertSame(1, $version->records()->whereNull('document')->count());
        $this->assertSame('MADEIREIRA EXEMPLO', $version->records()->where('source_key', '1001')->value('name_normalized'));
    }

    public function test_same_file_twice_is_a_noop(): void
    {
        $ingestor = app(IbamaEmbargosIngestor::class);
        $ingestor->ingest(base_path('tests/Fixtures/ibama_embargos.csv'));

        $this->assertNull($ingestor->ingest(base_path('tests/Fixtures/ibama_embargos.csv')));
        $this->assertSame(1, DatasetVersion::count());
    }

    public function test_swap_replaces_active_version(): void
    {
        $ingestor = app(IbamaEmbargosIngestor::class);
        $old = $ingestor->ingest($this->csv(10));
        $new = $ingestor->ingest($this->csv(12));

        $this->assertFalse($old->refresh()->is_active);
        $this->assertTrue($new->is_active);
        $this->assertSame(1, DatasetVersion::where('is_active', true)->count());
    }

    public function test_absurd_shrink_is_rejected_and_old_version_stays_active(): void
    {
        $ingestor = app(IbamaEmbargosIngestor::class);
        $old = $ingestor->ingest($this->csv(10));

        try {
            $ingestor->ingest($this->csv(2));
            $this->fail('esperava rejeição');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('diff absurdo', $e->getMessage());
        }

        $this->assertTrue($old->refresh()->is_active);
        $this->assertSame('rejected', DatasetVersion::latest('id')->first()->status);
    }
}

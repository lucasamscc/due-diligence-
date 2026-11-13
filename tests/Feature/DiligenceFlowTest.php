<?php

namespace Tests\Feature;

use App\Datasets\IbamaEmbargosIngestor;
use App\Diligence\Actions\CreateDiligence;
use App\Diligence\Actions\RetryDiligenceItem;
use App\Diligence\Jobs\QuerySourceJob;
use App\Diligence\Models\Diligence;
use App\Sources\Data\Severity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DiligenceFlowTest extends TestCase
{
    use RefreshDatabase;

    private const CNPJ = '11222333000181';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private bool $transparenciaUp = true;

    private function fakeSources(bool $transparenciaUp = true): void
    {
        $this->transparenciaUp = $transparenciaUp;

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, 'brasilapi.com.br')) {
                return Http::response([
                    'cnpj' => self::CNPJ,
                    'razao_social' => 'MADEIREIRA EXEMPLO LTDA',
                    'descricao_situacao_cadastral' => 'BAIXADA',
                    'data_inicio_atividade' => '2010-01-01',
                ]);
            }

            if (! $this->transparenciaUp) {
                return Http::response('Unauthorized', 401);
            }

            if (str_contains($url, '/ceis') && str_contains($url, 'pagina=1')) {
                return Http::response([[
                    'id' => 77, 'dataInicioSancao' => '01/01/2025', 'dataFimSancao' => null,
                    'tipoSancao' => ['descricaoResumida' => 'Inidoneidade'], 'orgaoSancionador' => ['nome' => 'CGU'],
                ]]);
            }

            return Http::response([]);
        });
    }

    public function test_fan_out_publishes_one_job_per_source_on_its_provider_connection(): void
    {
        Queue::fake();

        $diligence = app(CreateDiligence::class)->handle(self::CNPJ);

        $this->assertSame('running', (string) $diligence->status);
        $this->assertCount(5, $diligence->config_snapshot['sources']);
        Queue::assertPushed(QuerySourceJob::class, 5);
        Queue::assertPushed(QuerySourceJob::class, fn (QuerySourceJob $job) => $job->provider === 'transparencia' && $job->connection === 'rabbitmq_transparencia');
        Queue::assertPushed(QuerySourceJob::class, fn (QuerySourceJob $job) => $job->provider === 'ibama' && $job->connection === 'rabbitmq_dataset');
    }

    public function test_full_flow_collects_normalizes_and_scores(): void
    {
        $this->useSyncQueues();
        $this->fakeSources();
        app(IbamaEmbargosIngestor::class)->ingest(base_path('tests/Fixtures/ibama_embargos.csv'));

        $diligence = app(CreateDiligence::class)->handle(self::CNPJ)->refresh();

        $this->assertSame('completed', (string) $diligence->status);
        $this->assertTrue($diligence->items->every(fn ($i) => (string) $i->status === 'succeeded'));
        $this->assertSame(1, $diligence->findings()->where('type', 'environmental')->where('severity', 'high')->count());
        $this->assertSame(1, $diligence->findings()->where('type', 'environmental')->where('severity', 'low')->count(), 'desembargado não é risco vigente');
        $this->assertSame(1, $diligence->findings()->where('type', 'sanction')->where('severity', 'high')->count());

        // Payload cru vai para storage; o Postgres guarda só chave + hash.
        $item = $diligence->items->firstWhere('provider', 'brasilapi');
        Storage::disk('local')->assertExists($item->raw_payload_key);
        $this->assertSame(64, strlen($item->raw_payload_hash));

        $assessment = $diligence->latestAssessment;
        $this->assertSame('high', $assessment->level);
        $this->assertTrue($assessment->is_complete);
        $this->assertEqualsCanonicalizing(
            ['registration_not_active', 'active_sanction', 'environmental_embargo', 'past_embargo'],
            array_column($assessment->alerts, 'code'),
        );
    }

    public function test_partial_failure_is_completed_with_errors_and_retry_reopens_only_that_source(): void
    {
        $this->useSyncQueues();
        $this->fakeSources(transparenciaUp: false);
        app(IbamaEmbargosIngestor::class)->ingest(base_path('tests/Fixtures/ibama_embargos.csv'));

        $diligence = app(CreateDiligence::class)->handle(self::CNPJ)->refresh();

        $this->assertSame('completed_with_errors', (string) $diligence->status);
        $failed = $diligence->items->where('provider', 'transparencia');
        $this->assertCount(3, $failed);
        $this->assertTrue($failed->every(fn ($i) => (string) $i->status === 'failed'));
        // failed_jobs + DLQ só são gravados pelo worker real (rabbitmq:consume); no driver sync, não.
        $this->assertFalse($diligence->latestAssessment->is_complete);
        $this->assertContains('incomplete_coverage', array_column($diligence->latestAssessment->alerts, 'code'));

        // Fornecedor voltou: reprocessa só o CEIS. BrasilAPI não é chamada de novo.
        // (Http::fake acumula stubs; por isso a flag mutável em vez de um segundo fake.)
        $this->transparenciaUp = true;
        $before = Http::recorded()->count();
        app(RetryDiligenceItem::class)->handle($failed->firstWhere('product', 'ceis'));

        $after = Http::recorded()->slice($before)->map(fn ($pair) => $pair[0]->url());
        $this->assertTrue($after->every(fn ($url) => str_contains($url, '/ceis')), 'só o CEIS deveria ser consultado');
        $diligence->refresh();
        $this->assertSame('completed_with_errors', (string) $diligence->status);
        $this->assertSame(2, $diligence->assessments()->count());
        $this->assertSame(Severity::High, $diligence->findings()->where('type', 'sanction')->first()->severity);
    }

    public function test_without_active_dataset_the_source_fails_instead_of_returning_clean(): void
    {
        $this->useSyncQueues();
        $this->fakeSources();

        $diligence = app(CreateDiligence::class)->handle(self::CNPJ)->refresh();

        $ibama = $diligence->items->firstWhere('provider', 'ibama');
        $this->assertSame('failed', (string) $ibama->status);
        $this->assertStringContainsString('sem versão ativa', $ibama->error_message);
        $this->assertSame('completed_with_errors', (string) $diligence->status);
    }

    public function test_api_creates_and_shows_diligence(): void
    {
        Queue::fake();

        $id = $this->postJson('/api/diligences', ['document' => '11.222.333/0001-81'])
            ->assertCreated()
            ->assertJsonPath('data.document', '11.222.333/0001-81')
            ->json('data.id');

        $this->getJson("/api/diligences/{$id}")->assertOk()->assertJsonCount(5, 'data.items');
        $this->postJson('/api/diligences', ['document' => '529.982.247-25'])->assertUnprocessable();
    }

    public function test_reevaluation_does_not_query_sources_again(): void
    {
        $this->useSyncQueues();
        $this->fakeSources();
        app(IbamaEmbargosIngestor::class)->ingest(base_path('tests/Fixtures/ibama_embargos.csv'));
        $diligence = app(CreateDiligence::class)->handle(self::CNPJ);
        $sent = Http::recorded()->count();

        $this->artisan('diligence:reevaluate', ['id' => $diligence->id])->assertSuccessful();

        $this->assertSame($sent, Http::recorded()->count());
        $this->assertSame(2, Diligence::find($diligence->id)->assessments()->count());
    }
}

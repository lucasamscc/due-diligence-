<?php

namespace Tests\Unit;

use App\Sources\Data\Severity;
use App\Sources\Transparencia\TransparenciaNormalizer;
use Tests\TestCase;

class TransparenciaNormalizerTest extends TestCase
{
    public function test_active_and_expired_sanctions(): void
    {
        $findings = (new TransparenciaNormalizer)->normalize(['product' => 'ceis', 'records' => [
            ['id' => 1, 'dataInicioSancao' => '01/02/2025', 'dataFimSancao' => null,
                'tipoSancao' => ['descricaoResumida' => 'Impedimento'], 'orgaoSancionador' => ['nome' => 'Prefeitura X']],
            ['id' => 2, 'dataInicioSancao' => '01/02/2019', 'dataFimSancao' => '01/02/2020',
                'tipoSancao' => ['descricaoResumida' => 'Suspensão'], 'orgaoSancionador' => ['nome' => 'Ministério Y']],
        ]]);

        $this->assertSame([Severity::High, Severity::Low], $findings->pluck('severity')->all());
        $this->assertTrue($findings[0]->details['active']);
        $this->assertFalse($findings[1]->details['active']);
        $this->assertSame('2025-02-01', $findings[0]->occurredAt->toDateString());
        $this->assertSame('transparencia:ceis:1', $findings[0]->sourceReference);
    }

    public function test_empty_list_records_that_it_was_checked(): void
    {
        $findings = (new TransparenciaNormalizer)->normalize(['product' => 'cnep', 'records' => []]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Info, $findings->first()->severity);
        $this->assertStringContainsString('CNEP', $findings->first()->title);
    }
}

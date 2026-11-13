<?php

namespace Tests\Unit;

use App\Sources\BrasilApi\BrasilApiNormalizer;
use App\Sources\Data\FindingType;
use App\Sources\Data\Severity;
use Tests\TestCase;

class BrasilApiNormalizerTest extends TestCase
{
    public function test_cadastral_payload_becomes_single_info_finding(): void
    {
        $findings = (new BrasilApiNormalizer)->normalize(['status' => 200, 'body' => [
            'cnpj' => '33000167000101',
            'razao_social' => 'PETROLEO BRASILEIRO S A PETROBRAS',
            'nome_fantasia' => '',
            'descricao_situacao_cadastral' => 'Ativa',
            'data_inicio_atividade' => '1966-09-28',
            'qsa' => [['nome_socio' => 'FULANO', 'qualificacao_socio' => 'Diretor']],
        ]]);

        $this->assertCount(1, $findings);
        $f = $findings->first();
        $this->assertSame(FindingType::Cadastral, $f->type);
        $this->assertSame(Severity::Info, $f->severity);
        $this->assertSame('ATIVA', $f->details['situacao_cadastral']);
        $this->assertNull($f->details['nome_fantasia']);
        $this->assertSame('FULANO', $f->details['socios'][0]['nome']);
        $this->assertSame('1966-09-28', $f->occurredAt->toDateString());
    }

    public function test_not_found_is_a_finding_not_an_error(): void
    {
        $findings = (new BrasilApiNormalizer)->normalize(['status' => 404, 'body' => ['message' => 'CNPJ não encontrado']]);

        $this->assertFalse($findings->first()->details['found']);
    }
}

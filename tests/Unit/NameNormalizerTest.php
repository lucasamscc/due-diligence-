<?php

namespace Tests\Unit;

use App\Datasets\NameNormalizer;
use PHPUnit\Framework\TestCase;

class NameNormalizerTest extends TestCase
{
    public function test_strips_accents_and_corporate_suffixes(): void
    {
        $this->assertSame('MADEIREIRA SAO JOAO', NameNormalizer::normalize('Madeireira São João Ltda.'));
        $this->assertSame('AGROPECUARIA TESTE', NameNormalizer::normalize('Agropecuária Teste S.A.'));
        $this->assertNull(NameNormalizer::normalize('  '));
    }
}

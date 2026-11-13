<?php

namespace Tests\Unit;

use App\Diligence\Support\Cnpj;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CnpjTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_validation(string $cnpj, bool $valid): void
    {
        $this->assertSame($valid, Cnpj::isValid($cnpj));
    }

    public static function cases(): array
    {
        return [
            'numérico formatado' => ['33.000.167/0001-01', true],
            'numérico cru' => ['00000000000191', true],
            'alfanumérico (exemplo oficial da Receita)' => ['12.ABC.345/01DE-35', true],
            'dígito errado' => ['33.000.167/0001-02', false],
            'repetido' => ['11111111111111', false],
            'CPF' => ['529.982.247-25', false],
            'curto' => ['123', false],
        ];
    }

    public function test_format(): void
    {
        $this->assertSame('33.000.167/0001-01', Cnpj::format('33000167000101'));
    }
}

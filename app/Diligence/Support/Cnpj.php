<?php

namespace App\Diligence\Support;

/**
 * Validação de CNPJ, incluindo o formato alfanumérico (vigente desde jul/2026):
 * 12 posições alfanuméricas + 2 dígitos verificadores numéricos.
 * Cada caractere vale ord(c) - 48, então para dígitos o cálculo é o tradicional.
 */
final class Cnpj
{
    public static function strip(string $value): string
    {
        return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $value));
    }

    public static function isValid(string $value): bool
    {
        $cnpj = self::strip($value);

        if (! preg_match('/^[0-9A-Z]{12}[0-9]{2}$/', $cnpj)) {
            return false;
        }

        if (preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        $base = substr($cnpj, 0, 12);
        $dv1 = self::digit($base);
        $dv2 = self::digit($base.$dv1);

        return substr($cnpj, 12) === $dv1.$dv2;
    }

    public static function format(string $value): string
    {
        $c = self::strip($value);

        return sprintf('%s.%s.%s/%s-%s', substr($c, 0, 2), substr($c, 2, 3), substr($c, 5, 3), substr($c, 8, 4), substr($c, 12, 2));
    }

    private static function digit(string $base): string
    {
        $weights = strlen($base) === 12
            ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]
            : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        $sum = 0;
        foreach (str_split($base) as $i => $char) {
            $sum += (ord($char) - 48) * $weights[$i];
        }

        $rest = $sum % 11;

        return (string) ($rest < 2 ? 0 : 11 - $rest);
    }
}

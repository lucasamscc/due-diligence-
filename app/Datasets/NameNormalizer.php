<?php

namespace App\Datasets;

use Illuminate\Support\Str;

/** Maiúsculas, sem acento, sem sufixo societário, espaços colapsados — antes de qualquer trigram. */
final class NameNormalizer
{
    private const SUFFIXES = '/\b(LTDA|ME|EPP|EIRELI|S\/?A|SA|S\.A|CIA|COMPANHIA|LIMITADA)\b\.?/';

    public static function normalize(?string $name): ?string
    {
        if (blank($name)) {
            return null;
        }

        $n = mb_strtoupper(Str::ascii($name));
        $n = str_replace(['S.A.', 'S.A', 'S/A'], ' ', $n);
        $n = preg_replace(self::SUFFIXES, ' ', $n);
        $n = preg_replace('/[^A-Z0-9 ]/', ' ', $n);

        return trim(preg_replace('/\s+/', ' ', $n)) ?: null;
    }
}

<?php

namespace App\Sources\Data;

enum Severity: string
{
    case Info = 'info';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function isRisk(): bool
    {
        return $this !== self::Info;
    }
}

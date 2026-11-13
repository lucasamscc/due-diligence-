<?php

namespace App\Diligence\States\Diligence;

class Running extends DiligenceState
{
    public static $name = 'running';

    public function isFinal(): bool
    {
        return false;
    }
}

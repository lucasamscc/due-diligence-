<?php

namespace App\Diligence\States\Diligence;

class Completed extends DiligenceState
{
    public static $name = 'completed';

    public function isFinal(): bool
    {
        return true;
    }
}

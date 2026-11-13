<?php

namespace App\Diligence\States\Diligence;

class Failed extends DiligenceState
{
    public static $name = 'failed';

    public function isFinal(): bool
    {
        return true;
    }
}

<?php

namespace App\Diligence\States\Diligence;

class Pending extends DiligenceState
{
    public static $name = 'pending';

    public function isFinal(): bool
    {
        return false;
    }
}

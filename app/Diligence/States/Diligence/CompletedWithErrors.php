<?php

namespace App\Diligence\States\Diligence;

class CompletedWithErrors extends DiligenceState
{
    public static $name = 'completed_with_errors';

    public function isFinal(): bool
    {
        return true;
    }
}

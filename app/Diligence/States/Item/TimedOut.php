<?php

namespace App\Diligence\States\Item;

class TimedOut extends ItemState
{
    public static $name = 'timed_out';

    public function isFinal(): bool
    {
        return true;
    }
}

<?php

namespace App\Diligence\States\Item;

class Pending extends ItemState
{
    public static $name = 'pending';

    public function isFinal(): bool
    {
        return false;
    }
}

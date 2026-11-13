<?php

namespace App\Diligence\States\Item;

class Failed extends ItemState
{
    public static $name = 'failed';

    public function isFinal(): bool
    {
        return true;
    }
}

<?php

namespace App\Diligence\States\Item;

class Succeeded extends ItemState
{
    public static $name = 'succeeded';

    public function isFinal(): bool
    {
        return true;
    }
}

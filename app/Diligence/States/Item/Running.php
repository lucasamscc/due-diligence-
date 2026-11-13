<?php

namespace App\Diligence\States\Item;

class Running extends ItemState
{
    public static $name = 'running';

    public function isFinal(): bool
    {
        return false;
    }
}

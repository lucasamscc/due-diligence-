<?php

namespace App\Diligence\States\Item;

class AwaitingCallback extends ItemState
{
    public static $name = 'awaiting_callback';

    public function isFinal(): bool
    {
        return false;
    }
}

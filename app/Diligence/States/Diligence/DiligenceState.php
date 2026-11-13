<?php

namespace App\Diligence\States\Diligence;

use App\Diligence\Models\Diligence;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * @extends State<Diligence>
 */
abstract class DiligenceState extends State
{
    abstract public function isFinal(): bool;

    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Pending::class)
            ->allowTransition(Pending::class, Running::class)
            ->allowTransition(Running::class, Completed::class)
            ->allowTransition(Running::class, CompletedWithErrors::class)
            ->allowTransition(Running::class, Failed::class)
            // Reprocessar uma fonte reabre a diligência.
            ->allowTransition(CompletedWithErrors::class, Running::class)
            ->allowTransition(Failed::class, Running::class);
    }
}

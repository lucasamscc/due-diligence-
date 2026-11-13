<?php

namespace App\Diligence\States\Item;

use App\Diligence\Models\DiligenceItem;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * Estado de uma consulta a UMA fonte.
 *
 * "Final" aqui significa: o fan-in já pode contar este item como chegado.
 * AwaitingCallback NÃO é final — é exatamente o caso em que Bus::batch erraria,
 * considerando o job concluído (ack) enquanto o resultado ainda não existe.
 *
 * @extends State<DiligenceItem>
 */
abstract class ItemState extends State
{
    abstract public function isFinal(): bool;

    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Pending::class)
            ->allowTransition(Pending::class, Running::class)
            ->allowTransition(Pending::class, Failed::class)
            ->allowTransition(Running::class, Succeeded::class)
            ->allowTransition(Running::class, Failed::class)
            ->allowTransition(Running::class, AwaitingCallback::class)
            ->allowTransition(AwaitingCallback::class, Succeeded::class)
            ->allowTransition(AwaitingCallback::class, Failed::class)
            ->allowTransition(AwaitingCallback::class, TimedOut::class)
            // Reprocessar só esta fonte.
            ->allowTransition(Failed::class, Pending::class)
            ->allowTransition(TimedOut::class, Pending::class);
    }
}

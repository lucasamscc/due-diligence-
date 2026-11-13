<?php

namespace App\Diligence\Support;

use Illuminate\Support\Facades\Redis;

/**
 * Contador de fan-in no Redis: "quantos itens desta diligência ainda não chegaram".
 *
 * Por que não Bus::batch()->then()? O batch conta JOBS concluídos (ack). Um item em
 * awaiting_callback teve o job concluído, mas o resultado ainda não existe — o then()
 * dispararia cedo. Aqui quem decrementa é o ITEM ao chegar num estado final.
 *
 * O Redis é descartável: se o contador sumir, DECR devolve negativo, o finalize é
 * disparado, confere o Postgres (fonte de verdade) e não faz nada se ainda falta item.
 * O comando diligence:reconcile cobre o caso restante.
 */
class FanIn
{
    private const TTL = 7 * 86400;

    public function expect(int $diligenceId, int $count): void
    {
        Redis::set($this->key($diligenceId), $count, 'EX', self::TTL);
    }

    public function add(int $diligenceId, int $count = 1): void
    {
        Redis::incrby($this->key($diligenceId), $count);
        Redis::expire($this->key($diligenceId), self::TTL);
    }

    /** @return bool true se este foi o último item esperado */
    public function arrive(int $diligenceId): bool
    {
        // DECR é atômico no servidor: dois workers chegando juntos nunca leem o mesmo valor.
        return Redis::decr($this->key($diligenceId)) <= 0;
    }

    public function pending(int $diligenceId): ?int
    {
        $value = Redis::get($this->key($diligenceId));

        return $value === null || $value === false ? null : (int) $value;
    }

    private function key(int $diligenceId): string
    {
        return "diligence:{$diligenceId}:pending";
    }
}

<?php

namespace App\Diligence\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue;

/**
 * Declara exchanges, filas, DLX e bindings. Idempotente: declarar de novo com os
 * MESMOS argumentos é no-op no broker. Mudar argumento de fila existente dá
 * PRECONDITION_FAILED — aí use --fresh (apaga as filas, e as mensagens nelas).
 */
class DeclareRabbitTopology extends Command
{
    protected $signature = 'rabbitmq:topology {--fresh : Apaga as filas antes de declarar (perde mensagens)}';

    protected $description = 'Declara a topologia RabbitMQ das diligências (exchange topic, filas, DLX, DLQs)';

    public function handle(): int
    {
        /** @var RabbitMQQueue $rabbit */
        $rabbit = Queue::connection('rabbitmq_control');
        $t = config('queue.topology');

        if ($this->option('fresh')) {
            foreach (array_keys($t['queues']) as $queue) {
                $rabbit->deleteQueue($queue);
                $rabbit->deleteQueue("{$queue}.dlq");
            }
            foreach (array_keys($t['taps']) as $tap) {
                $rabbit->deleteQueue($tap);
            }
            $this->warn('Filas apagadas.');
        }

        $rabbit->declareExchange($t['exchange'], 'topic');
        $rabbit->declareExchange($t['dlx'], 'topic');
        $this->line("exchange <info>{$t['exchange']}</info> (topic) e <info>{$t['dlx']}</info> (topic)");

        foreach ($t['queues'] as $queue => $routingKey) {
            // DLQ primeiro: é o destino do dead-lettering da fila principal.
            $rabbit->declareQueue("{$queue}.dlq");
            $rabbit->bindQueue("{$queue}.dlq", $t['dlx'], "dead.{$queue}");

            // basic_reject(requeue=false) — o que o pacote faz no markAsFailed() —
            // manda a mensagem para x-dead-letter-exchange com esta routing key.
            $rabbit->declareQueue($queue, arguments: [
                'x-dead-letter-exchange' => $t['dlx'],
                'x-dead-letter-routing-key' => "dead.{$queue}",
            ]);
            $rabbit->bindQueue($queue, $t['exchange'], $routingKey);

            $this->line("  {$t['exchange']} --[{$routingKey}]--> <info>{$queue}</info>  (falha → {$t['dlx']} --[dead.{$queue}]--> {$queue}.dlq)");
        }

        foreach ($t['taps'] as $tap => $opts) {
            // Grampo: só existe para ver o wildcard do topic funcionando na Management UI.
            // Recebe cópia de toda mensagem source.* (inclusive as que voltam de retry).
            $rabbit->declareQueue($tap, arguments: ['x-max-length' => $opts['max_length']]);
            $rabbit->bindQueue($tap, $t['exchange'], $opts['binding']);
            $this->line("  {$t['exchange']} --[{$opts['binding']}]--> <info>{$tap}</info>  (max {$opts['max_length']})");
        }

        $this->newLine();
        $this->info('Topologia declarada. Veja em http://localhost:15672 → Exchanges → diligence → Bindings.');

        return self::SUCCESS;
    }
}

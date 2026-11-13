<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Redis;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // FanIn usa Redis de verdade (DB 15 em testes): o contador é parte do mecanismo testado.
        Redis::flushdb();
    }

    /** Troca as connections RabbitMQ por sync: middleware, failed() e fan-in rodam de verdade, sem broker. */
    protected function useSyncQueues(): void
    {
        foreach (['rabbitmq_brasilapi', 'rabbitmq_transparencia', 'rabbitmq_dataset', 'rabbitmq_control'] as $connection) {
            config(["queue.connections.{$connection}" => ['driver' => 'sync']]);
        }
    }
}

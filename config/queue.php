<?php

$rabbit = fn (array $extra) => [
    'driver' => 'rabbitmq',
    'connection' => 'default',
    'hosts' => [[
        'host' => env('RABBITMQ_HOST', '127.0.0.1'),
        'port' => env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
    ]],
    'options' => $extra['options'] ?? [],
    'worker' => env('RABBITMQ_WORKER', 'default'),
    'after_commit' => true,
] + $extra;

$topic = fn (string $queue, string $routingKey) => [
    'queue' => $queue,
    'options' => ['queue' => [
        'exchange' => 'diligence',
        'exchange_type' => 'topic',
        // %s = nome da fila. Delay queues (retry com TTL) usam a MESMA routing key
        // como x-dead-letter-routing-key, então a mensagem volta pela exchange.
        'exchange_routing_key' => $routingKey,
    ]],
];

$source = fn (string $queue) => $topic($queue, 'source.%s');

return [

    /*
    |--------------------------------------------------------------------------
    | Topologia RabbitMQ (declarada por php artisan rabbitmq:topology)
    |--------------------------------------------------------------------------
    |
    | exchange "diligence" (topic)
    |   source.brasilapi     → fila brasilapi
    |   source.transparencia → fila transparencia
    |   source.dataset       → fila dataset
    |   control.diligence    → fila diligence        (finalize + regras)
    |   source.#             → fila audit.sources    (grampo: cópia de tudo, max 1000)
    |
    | exchange "diligence.dlx" (topic) — reject(requeue=false) de cada fila cai aqui
    |   dead.<fila>          → fila <fila>.dlq
    |
    */

    'topology' => [
        'exchange' => 'diligence',
        'dlx' => 'diligence.dlx',
        'queues' => [
            'brasilapi' => 'source.brasilapi',
            'transparencia' => 'source.transparencia',
            'dataset' => 'source.dataset',
            'diligence' => 'control.diligence',
        ],
        'taps' => [
            'audit.sources' => ['binding' => 'source.#', 'max_length' => 1000],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        // Connection "pura" do passo 1 (SmokeTestJob): sem exchange, publica direto
        // na fila "default" pela default exchange. O pacote declara a fila sozinho.
        'rabbitmq' => $rabbit(['queue' => env('RABBITMQ_QUEUE', 'default')]),

        // Uma connection por fornecedor. Todas publicam na MESMA exchange topic
        // "diligence"; o que muda é a routing key (source.<fila>), e é o binding
        // declarado em rabbitmq:topology que leva cada uma à sua fila.
        // Com exchange configurada o pacote NÃO declara fila nem binding — por isso
        // a topologia é explícita (php artisan rabbitmq:topology).
        'rabbitmq_brasilapi' => $rabbit($source('brasilapi')),
        'rabbitmq_transparencia' => $rabbit($source('transparencia')),
        'rabbitmq_dataset' => $rabbit($source('dataset')),
        'rabbitmq_control' => $rabbit($topic('diligence', 'control.%s')),

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => null,
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];

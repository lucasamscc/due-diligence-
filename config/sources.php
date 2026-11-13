<?php

/*
| Configuração de INFRA por fornecedor (URL, chave, timeout, limites, fila).
| O ESCOPO da diligência (quais fontes/produtos rodam) mora no SourceRegistry
| e é congelado no config_snapshot — mudar este arquivo não altera diligências
| já criadas, só como elas são executadas.
*/

return [

    'raw_disk' => env('SOURCES_RAW_DISK', 'local'),

    'brasilapi' => [
        'base_url' => env('BRASILAPI_URL', 'https://brasilapi.com.br/api'),
        'timeout' => 15,
        'rate_per_minute' => (int) env('BRASILAPI_RATE_PER_MINUTE', 30),
        'connection' => 'rabbitmq_brasilapi',
    ],

    'transparencia' => [
        'base_url' => env('TRANSPARENCIA_URL', 'https://api.portaldatransparencia.gov.br/api-de-dados'),
        'api_key' => env('TRANSPARENCIA_API_KEY'),
        'timeout' => 20,
        // Limite oficial ~90/min em horário comercial; deixamos folga.
        'rate_per_minute' => (int) env('TRANSPARENCIA_RATE_PER_MINUTE', 60),
        'max_pages' => 10,
        'connection' => 'rabbitmq_transparencia',
    ],

    'ibama' => [
        // Lookup local: sem rate limit externo, mas mesma fila "dataset" para todas as listas.
        'rate_per_minute' => 600,
        'connection' => 'rabbitmq_dataset',
    ],

    'circuit_breaker' => [
        // N falhas transitórias do MESMO fornecedor dentro da janela abrem o circuito.
        'max_failures' => 5,
        'decay_seconds' => 300,
    ],

    // Tentativas reais de chamada (não conta releases por rate limit).
    'max_attempts' => 4,
    'backoff_seconds' => [30, 120, 600],

    'datasets' => [
        'ibama_embargos' => [
            'url' => env('IBAMA_EMBARGOS_URL', 'https://dadosabertos.ibama.gov.br/dados/SIFISC/termo_embargo/termo_embargo/termo_embargo_csv.zip'),
            'delimiter' => ';',
            'encoding' => 'UTF-8',
            // Validação do swap: abaixo disso a versão nova é rejeitada.
            'min_rows' => (int) env('IBAMA_MIN_ROWS', 50000),
            // Versão nova com menos que X% das linhas da ativa = arquivo truncado/quebrado.
            'max_shrink_ratio' => 0.5,
            // Cabeçalhos aceitos (o primeiro encontrado vence). Confira contra o arquivo real.
            'columns' => [
                'key' => ['SEQ_TAD', 'NUM_TAD', 'NUMERO_TAD'],
                'document' => ['CPF_CNPJ_EMBARGADO', 'CPF_CNPJ', 'NUM_CPF_CNPJ'],
                'name' => ['NOME_EMBARGADO', 'NOME_PESSOA_EMBARGADA', 'NOM_PESSOA'],
                'date' => ['DAT_EMBARGO', 'DATA_EMBARGO', 'DAT_TAD'],
                'municipality' => ['MUNICIPIO', 'NOM_MUNICIPIO'],
                'uf' => ['UF', 'SIG_UF'],
                'description' => ['DES_TAD', 'DES_INFRACAO', 'DESCRICAO'],
                'process' => ['NUM_PROCESSO'],
                // Embargo cancelado ou já desembargado continua na lista — não é risco vigente.
                'cancelled' => ['SIT_CANCELADO'],
                'lifted_at' => ['DAT_DESEMBARGO'],
            ],
        ],
    ],
];

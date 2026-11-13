# Diligência — background check de CNPJ com RabbitMQ

Projeto de estudo de **filas distribuídas com RabbitMQ em Laravel**. Você informa um CNPJ, o sistema
consulta todas as fontes registradas em paralelo (uma fila por fornecedor), normaliza tudo em `Finding`s
e aplica regras de risco. Decisões e armadilhas estão em [CLAUDE.md](CLAUDE.md).

## Subindo

```bash
docker compose up -d                 # postgres (5440), rabbitmq (5672/15672), redis (6379)
composer install && cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan rabbitmq:topology        # exchanges, filas, DLX, DLQs, bindings
php artisan datasets:ingest-ibama    # baixa ~47 MB, ~114 mil embargos (ou --file=tests/Fixtures/ibama_embargos.csv)
```

Sem `TRANSPARENCIA_API_KEY` as três fontes do Portal falham — e a diligência fecha como
`completed_with_errors`, que é exatamente o comportamento de falha parcial desejado.

## Workers (um por fila)

```bash
php artisan rabbitmq:consume rabbitmq_brasilapi     --queue=brasilapi     --prefetch-count=1
php artisan rabbitmq:consume rabbitmq_transparencia --queue=transparencia --prefetch-count=1
php artisan rabbitmq:consume rabbitmq_dataset       --queue=dataset       --prefetch-count=1
php artisan rabbitmq:consume rabbitmq_control       --queue=diligence     --prefetch-count=1
```

`rabbitmq:consume` usa `basic_consume` + `basic_qos`; o padrão do pacote é **prefetch 1000** — com jobs de
I/O longo isso faz um worker reservar mensagens que vai levar minutos para processar. Sempre passe
`--prefetch-count`. Worker é processo long-running: **reinicie após mudar código**.

## Usando

```bash
php artisan diligence:run 33.000.167/0001-01
php artisan diligence:show 1
php artisan diligence:retry-item 7          # reprocessa só uma fonte failed
php artisan diligence:reevaluate 1          # regras de novo, sem reconsultar nada
```

API: `POST /api/diligences {document}`, `GET /api/diligences/{id}`,
`POST /api/diligences/{id}/items/{item}/retry`, `POST /api/diligences/{id}/evaluate`.

## Topologia

```
exchange diligence (topic)
  source.brasilapi      → brasilapi
  source.transparencia  → transparencia
  source.dataset        → dataset
  control.diligence     → diligence          (FinalizeDiligenceJob: fan-in + regras)
  source.#              → audit.sources      (grampo, max 1000 — mostra o wildcard)

exchange diligence.dlx (topic)
  dead.<fila>           → <fila>.dlq         (reject requeue=false)

<fila>.delay.<ms>  (criadas sob demanda pelo pacote)
  x-message-ttl=<ms>, x-dead-letter-exchange=diligence, routing key source.<fila>
```

## Ciclo de vida de uma consulta

| Situação | O que acontece no broker | Conta tentativa? |
|---|---|---|
| Sucesso | ack | — |
| Mensagem de item já final (reentrega) | `Skip` → ack | não |
| Cache de consulta recente | resolve sem chamar fornecedor nem gastar token | não |
| Rate limit estourado (`RateLimited`) | release → `brasilapi.delay.N` → TTL → volta | não |
| Circuito aberto (`ThrottlesExceptions`) | release sem chamar o fornecedor | não |
| `TransientSourceException` (5xx/429/timeout) | release com backoff 30 → 120 → 600 s | sim (4 no total) |
| `PermanentSourceException` (4xx, sem chave, dataset vazio) | reject → `<fila>.dlq` + `failed_jobs` | falha na hora |
| Bug de código | `$backoff` do job, até `maxExceptions=3` | sim |

O job usa `retryUntil()` (2 h) em vez de `$tries`: cada release por rate limit incrementa o contador de
tentativas do Laravel, então `$tries=3` mataria jobs que só estavam esperando a vez.

## Fan-in

`Bus::batch` não é usado: ele conta jobs com ack, e um item `awaiting_callback` teria ack sem ter
resultado. Em vez disso, um contador Redis (`diligence:{id}:pending`, `SET` antes de publicar, `DECR`
quando o item chega a estado final). Quem chega a zero publica o `FinalizeDiligenceJob`, que confere no
Postgres (com `lockForUpdate`) se todos os itens são finais. Se o Redis perder o contador,
`diligence:reconcile` (agendado a cada 5 min) fecha o que ficou para trás.

## Exercícios para observar na Management UI (localhost:15672)

1. **Mensagem parada:** pare o worker de `brasilapi`, rode `diligence:run` e veja `Ready=1` na fila.
2. **Retry com TTL:** suba o worker com `BRASILAPI_URL=http://127.0.0.1:9/api` e veja
   `brasilapi.delay.30000` → `brasilapi.delay.120000` aparecerem e sumirem (`x-expires`).
3. **DLQ:** sem `TRANSPARENCIA_API_KEY`, as mensagens vão para `transparencia.dlq` *e* `failed_jobs`.
   `php artisan queue:retry all` republica a partir do `failed_jobs` — e o job é **descartado** pelo
   `Skip`, porque o item já está `failed` (estado final). Reprocessar é decisão de domínio:
   use `diligence:retry-item`, que reabre o item, reabre a diligência e incrementa o fan-in.
4. **Rate limit:** `BRASILAPI_RATE_PER_MINUTE=1` e três diligências seguidas.
5. **Topic wildcard:** `audit.sources` recebe cópia de tudo que casa `source.#`, inclusive as voltas de retry.

## Testes

Precisam de Postgres e Redis (Docker) — `jsonb`, `pg_trgm`, índice parcial e o contador de fan-in são
parte do que se testa.

```bash
docker compose exec postgres psql -U app -d diligencia -c "CREATE DATABASE diligencia_test"
php artisan test
```

## Pendências conscientes

- Guard de timeout para `awaiting_callback` — estado existe, guard só com fornecedor assíncrono real.
- Lookup por nome (`pg_trgm`) — índice criado, ainda sem fonte que precise (OFAC entraria aqui).
- Payload cru em disco local; trocar `SOURCES_RAW_DISK` para `s3`/MinIO quando vier.
- Versões antigas de dataset não são podadas.

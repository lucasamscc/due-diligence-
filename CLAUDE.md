# CLAUDE.md

Contexto do projeto de sistema de diligências. Leia antes de propor ou escrever código.

## O que é

Sistema de diligência (background check) de entidades. O usuário informa um CNPJ, e o sistema dispara consultas **assíncronas** a todas as fontes de risco registradas (ambiental, criminal, fiscal, sanções, judicial), normaliza os resultados e aplica regras de risco. Não há seleção de fontes pelo usuário nem conceito de workflow configurável — o conjunto de fontes consultadas é fixo no código (`SourceRegistry`), a mesma diligência roda tudo que estiver registrado.

Projeto de estudo. O objetivo real é dominar **RabbitMQ + filas distribuídas em Laravel** — não entregar produto. Quando houver escolha entre o caminho confortável e o que ensina mais sobre broker/filas, prefira o segundo e explique o trade-off.

Escopo inicial: **só CNPJ**. Não existe fonte pública gratuita para CPF; o CPF fica modelado mas não consultável.

## Stack

- PHP 8.3 / Laravel 13
- PostgreSQL 16 (fonte de verdade)
- RabbitMQ 3 (broker) — via `vladimir-yuldashev/laravel-queue-rabbitmq`
- Redis (cache, rate limit, circuit breaker, contador de fan-in)
- Storage local agora; S3/MinIO depois, para payload cru
- Tudo em Docker Compose local

Pacotes: `spatie/laravel-model-states`, `spatie/laravel-data`, `tpetry/laravel-postgresql-enhanced`.

**Horizon não é opção** — é Redis-only e não funciona com RabbitMQ. Observabilidade via Management UI (localhost:15672) e métricas próprias.

## Decisões arquiteturais (não reverter sem discutir)

### Duas entidades separadas

Sem `WorkflowTemplateVersion` — decisão original revertida: o projeto não tem seleção de fontes pelo usuário nem workflow versionado em banco. Toda diligência roda **todas as fontes registradas** no `SourceRegistry` (código, não tabela). O fan-out pra N fornecedores continua idêntico — só não existe mais a etapa de "usuário escolhe quais".

- `Diligence` — a execução. Guarda um **snapshot** (`config_snapshot` JSONB) da lista de fontes resolvidas pelo `SourceRegistry` no momento da criação — provider, produto e params de cada uma.
- `DiligenceItem` — uma linha por fonte consultada. É a unidade atômica de retry, estado, custo e resultado. Carrega `provider`, `product` e `params` já resolvidos, copiados do snapshot — nunca ponteiro para o registry.

Motivo do snapshot: diligência dura minutos/horas; se o registry fosse lido a cada job, um deploy que adicione/remova fornecedor no meio geraria execução inconsistente. Além disso, diligência é documento com valor probatório — o escopo executado precisa estar congelado junto com o resultado.

### Dois modos de fonte, um contrato

```php
interface RiskSourceProvider {
    public function dispatch(Entity $entity, SourceConfig $config): ProviderResponse;
    public function normalize(array $rawPayload): Collection; // Finding[]
}
```

- **Query-time** — chamada HTTP no momento da diligência (BrasilAPI, Portal da Transparência, DataJud).
- **Dataset local** — não há endpoint por documento. Pipeline agendado baixa/ingere o arquivo; a "consulta" é um lookup no próprio Postgres (IBAMA, lista suja MTE, OFAC/ONU/UE).

O contrato vale para os dois: `dispatch()` não precisa ser HTTP, pode ser um SELECT. A ingestão do dataset é um pipeline **totalmente independente**, nunca acionado por uma diligência.

`Entity` não é Eloquent — é um `Data` object (`spatie/laravel-data`) montado a partir de `diligences.entity_document` na hora do dispatch. Sem tabela `entities`: não há necessidade de persistir a entidade separada da diligência que a referencia.

`normalize()` devolve `Finding[]` **mesmo pra dado que não é risco**. Dado cadastral (nome fantasia, e-mail, telefone, situação cadastral, vindo do produto cadastral do BrasilAPI) vira `Finding` com `type: "cadastral"` e `severity: "info"` — sem tabela separada. Motivo: manter `normalize()` uniforme pra qualquer fornecedor evita um caminho especial só pro BrasilAPI no `Provider` e na engine de regras. Se um dia precisar de "perfil atual por CNPJ" pra consulta rápida (ex: cache pra evitar rebater rate limit), isso é um read-model derivado dos findings — não uma tabela de captura nova.

### Adapter por fornecedor, não por tema

Um fornecedor expõe N produtos. Dentro de cada fornecedor, separar em três arquivos porque quebram por motivos diferentes:

- `XClient` — só HTTP: auth, timeout, formato da requisição.
- `XNormalizer` — anti-corruption layer: payload cru → `Finding` canônico. Testável com array de entrada e array de saída, sem HTTP.
- `XProvider` — implementa o contrato, orquestra os dois.

Nada de payload de fornecedor vazando para o frontend ou para o motor de regras.

### Falha parcial é resultado válido

9 de 10 fontes retornando = `completed_with_errors`, não `failed`. O analista quer o que existe, com indicação clara do que faltou e opção de reprocessar só aquela fonte.

### Motor de regras separado da coleta

Roda depois da normalização, em passo próprio. Permite reavaliar diligências antigas com regras novas sem reconsultar (e repagar) nada. Não acoplar.

## Fluxo

```
Ingestão agendada (cron + diff)          Diligência (snapshot da config)
        ↓                                            ↓
Dataset versionado (swap atômico)          Exchange topic (routing por fornecedor)
        ↓                                     ↓       ↓        ↓
   Fila dataset ←──────────────────────────────  Fila brasilapi  Fila transparencia
   (lookup local)                                (HTTP + rate limit)
        ↓                                     ↓       ↓        ↓
              Findings normalizados (Postgres + raw no S3)
                              ↓
                    Motor de regras (score e alertas)
```

## Persistência

### Postgres

Colunas para o que se consulta, JSONB para o que só se exibe.

```
diligences          -- config_snapshot JSONB, status, entity_document
diligence_items     -- provider, product, params JSONB, status,
                    -- attempts, cost_cents, raw_payload_key, started_at
findings            -- diligence_item_id, type, severity, occurred_at,
                    -- title, details JSONB, source_reference
dataset_versions    -- source, published_at, ingested_at, row_count, is_active
dataset_records     -- dataset_version_id, document, name_normalized, payload JSONB
```

Payload cru **não** vai para o Postgres — vai para object storage; a tabela guarda chave + hash. Particionamento por range em `findings`/`diligence_items` só quando o volume pedir.

### Datasets: swap atômico obrigatório

Nunca `TRUNCATE` + `INSERT`. Existe janela com a tabela vazia, e diligência rodando nela retorna falso negativo silencioso — o pior bug possível num sistema de risco.

Padrão: ingerir a versão nova em paralelo, validar (contagem plausível, diff não absurdo), marcar como ativa numa transação. Consultas sempre filtram pela versão ativa.

Guardar `published_at` separado de `ingested_at`. Um alerta precisa dizer "conforme lista publicada em MM/AAAA", senão não se distingue "está limpo" de "sua cópia está velha".

### Matching por nome

Várias listas (OFAC principalmente) não trazem CNPJ, só razão social. O lookup é aproximado:

```sql
CREATE INDEX ON dataset_records USING gin (name_normalized gin_trgm_ops);

SELECT *, similarity(name_normalized, ?) AS score
FROM dataset_records
WHERE dataset_version_id = ? AND name_normalized % ?
ORDER BY score DESC;
```

Normalizar antes: maiúsculas, sem acento, sem `LTDA`/`S.A.`. O `Finding` gerado carrega o score e a versão do dataset — match por nome é probabilístico, quem decide é o analista.

### Redis

Só o descartável: rate limit (token bucket), circuit breaker, contador de fan-in, cache de consultas recentes. Nada que doa perder num flush.

## Fontes gratuitas

| Tema | Fonte | Acesso |
|---|---|---|
| Cadastral | BrasilAPI | sem token, sem limite fixo |
| Cadastral | ReceitaWS, CNPJ.ws | 3 req/min grátis |
| Sanções | Portal da Transparência (CEIS, CNEP, CEPIM, CEAF, leniência) | chave grátis via gov.br, Swagger |
| Judicial | DataJud (CNJ) | chave pública, Elasticsearch DSL, índice por tribunal |
| Ambiental | IBAMA embargos/autuações | arquivo |
| Trabalhista | Cadastro de Empregadores (lista suja) | arquivo |
| Internacional | OFAC, ONU, UE | download XML/CSV |

Notas: a Receita não tem API pública gratuita individual — o que existe é a base de dados abertos mensal que BrasilAPI/minhaReceita/OpenCNPJ processam. DataJud não permite consulta por CNPJ da parte (dados de partes são resguardados) e tem defasagem de horas a dias.

Escopo mínimo do projeto: BrasilAPI (cadastral) + Portal da Transparência (CEIS/CNEP/CEPIM) + um dataset local. Cobre os dois modos de adapter e gera findings heterogêneos o bastante para a normalização valer a pena.

## Laravel — o que usar

| Necessidade | Ferramenta |
|---|---|
| Fan-out/fan-in | `Bus::batch()` com `allowFailures()` |
| Retry com backoff | `$tries`, `$backoff = [30, 120, 600]` |
| Circuit breaker | middleware `ThrottlesExceptions` |
| Rate limit por fornecedor | middleware `RateLimited` + `RateLimiter::for()` |
| Idempotência | `ShouldBeUnique`, chave `diligence_id:source_id` |
| HTTP | `Http::retry()`, `timeout()` |
| Ingestão | Scheduler `withoutOverlapping()->onOneServer()` |
| CSV grande | `league/csv` + `LazyCollection::chunk()` + `upsert()` |
| Testes | `Http::fake()`, `Bus::fake()`, `Storage::fake()` |

Motor de regras: `Illuminate\Pipeline\Pipeline` com regras como classes PHP. Não existe pacote bom pronto. Regra editável em runtime (`symfony/expression-language`) é projeto à parte — não fazer agora.

## Armadilhas conhecidas

- **`Bus::batch` não fecha com callback externo.** Se um item termina em `awaiting_callback`, o batch considera concluído e dispara `then()` cedo. Fan-in precisa de contador próprio no Redis (`DECR`).
- **Topologia RabbitMQ mora na config, não no job.** A abstração do Laravel só conhece "fila com nome"; exchange/routing key/binding ficam em `config/queue.php`. Padrão: **uma connection por fornecedor**, job escolhe com `->onConnection('rabbitmq_brasilapi')`.
- **Fornecedor assíncrono (protocolo + webhook):** o job dispara, salva o protocolo, marca `awaiting_callback` e **encerra** (ack). Nunca fica em loop esperando — isso trava um worker por consulta. Precisa de job de guarda que marca `timeout`. Prever o estado agora, implementar só quando entrar fornecedor comercial.
- **Rate limits são brutos** (3 req/min). Fila-por-fornecedor com token bucket não é refinamento, é o coração do sistema. Cache de consulta recente é questão de viabilidade, não economia.
- **Prefetch baixo (1–3)** — jobs de I/O longo; prefetch alto faz o worker reservar mensagens que vai demorar minutos para processar.
- **Retry com backoff no RabbitMQ** = fila de retry com TTL + DLX apontando de volta para a principal. Não `sleep()` no worker.
- **Falha final aparece em dois lugares**: DLQ e `failed_jobs`.

## Organização de pastas

```
app/
├── Diligence/     Models, States, Actions, Jobs, Events
├── Sources/       Contracts, Data/Finding.php, SourceRegistry, {Fornecedor}/
├── Datasets/      Models, Actions, {Fonte}Ingestor
└── Rules/         Contracts, EvaluateFindings
```

`Sources/` tem **uma pasta por fornecedor** — é a única parte que cresce sem parar, e fornecedor novo é pasta nova sem tocar em nada existente.

Criar `Datasets/` e `Rules/` só quando chegar neles. Pasta vazia com contrato dentro é convite para abstrair cedo demais.

### Convenções

- **Actions**: uma operação de negócio, um `handle()` público, transação dentro. Se precisa de nome verbal claro e roda transação, é Action. Evitar `DiligenceService` acumulando 20 métodos.
- **Events**: só efeito colateral opcional (notificar, invalidar cache, métrica). Orquestração vai em Action ou Job, chamada explícita. O sistema já é assíncrono — evento como mecanismo de orquestração empilha dois fluxos de controle invisíveis.
- **Controllers finos**: valida, chama uma Action, devolve. `if` em controller está no lugar errado.
- **Não usar**: Repository sobre Eloquent, DDD completo com mapeamento manual, namespace fora de `App\`.

## Ordem de implementação

Uma coisa por vez. A cada passo de fila, abrir a Management UI e observar o que mudou.

1. Job que só faz `Log::info()`. Ver a mensagem parada na fila **antes** de subir o worker.
2. Migrations do domínio. Sem tela; `tinker` cria os registros.
3. Adapter BrasilAPI isolado, testado com `Http::fake()`, sem tocar em fila.
4. Juntar: job chama adapter, grava findings, transiciona estado. Fila única `default`, sem routing key.
5. Quebrar de propósito: URL inválida, ver retry, ver cair em `failed_jobs`, rodar `queue:retry`.
6. Só então: exchange topic, connection por fornecedor, DLX, retry com TTL, prefetch, fan-in.

Rodar o worker com `--tries=1` no começo — retry automático mascara bug de código como instabilidade de rede.

## Ambiente

Docker Compose: `postgres:16`, `rabbitmq:3-management` (5672 + 15672), `redis:7-alpine`.

Extensões PHP: `cli, pgsql, mbstring, xml, curl, bcmath, sockets, zip, redis`. `bcmath` e `sockets` são exigidos pelo `php-amqplib` e faltam com frequência.

`APP_FAKER_LOCALE=pt_BR` — o Faker brasileiro gera `cnpj()` e `cpf()` com dígito verificador válido.

`QUEUE_CONNECTION=rabbitmq` só funciona depois de existir a entrada `'rabbitmq'` em `config/queue.php`. O pacote não injeta sozinho; o bloco está no README. Erro `Driver [rabbitmq] not supported` = é isso.

## Como me ajudar

- Explicar o **porquê** das escolhas de fila/broker, não só entregar código. O objetivo é aprendizado.
- Ao propor algo que o Horizon resolveria, dizer o que se perde e como fazer sem.
- Não pular etapas da ordem de implementação acima, mesmo que o resultado final seja óbvio.
- Preferir a solução que exponha o mecanismo do RabbitMQ à que o esconda.
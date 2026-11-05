# Arcana Tarot — Arquitetura de produção no Hostoo

> Runbook da implantação em **hospedagem compartilhada (Hostoo)** — ambiente sem
> Redis, sem Reverb e sem worker permanente — mantendo Docker/Reverb intacto para
> o desenvolvimento local.
> Complementa [REALTIME.md](REALTIME.md) (WebSocket com Reverb) e
> [DEPLOYMENT.md](DEPLOYMENT.md) (runbook Docker). Para exercitar este mesmo
> perfil na máquina de desenvolvimento, antes do deploy, use
> [TESTE_LOCAL_MODO_HOSTOO.md](TESTE_LOCAL_MODO_HOSTOO.md).

---

## 1. Diagnóstico: o que o Hostoo não oferece

O projeto foi construído no Docker Compose, com seis serviços. Numa hospedagem
compartilhada, quatro deles não têm equivalente:

| Requisito do projeto | Como funciona no Docker | No Hostoo | Consequência |
|---|---|---|---|
| Servidor WebSocket (`laravel/reverb`) | serviço `reverb` + `php artisan reverb:start` | **não existe** processo persistente | sem canal `user.{id}` e `consultation.{id}` |
| Worker de fila | serviço `queue` (`queue:work redis`) | **não existe** | jobs só rodam quando o cron chama `queue:work` |
| Redis (fila/cache) | serviço `redis:7` | **não existe** | fila, cache e sessão precisam do MySQL |
| Scheduler | cron na máquina de operação | existe, via painel | precisa de duas entradas de cron; o encerramento por inatividade também roda na requisição (§6.1) |
| Nginx/PHP-FPM próprios | serviços `nginx` + `app` | gerenciados pelo provedor | document root aponta para `public/` |
| TLS | proxy do desenvolvedor | termina no proxy do provedor | `X-Forwarded-*` e cookies `secure` |
| Versão do PHP | fixada pela imagem `php:8.4-fpm-alpine` | **escolhida no painel** (MultiPHP) | precisa ser **8.4** — mínimo 8.4.1 (§1.1) |

Sem WebSocket e sem worker, o caminho "evento → fila → Reverb → navegador" não
fecha. Foi exatamente essa a mudança de arquitetura: **o navegador passa a ler o
estado por HTTP em intervalos curtos**, e as rotinas financeiras são acionadas
pelo cron.

### 1.1 PHP 8.4 é obrigatório no site **e** no cron

O `composer.json` exige `"php": "^8.4"` e os componentes do Symfony usados pelo
Laravel 13 exigem `>= 8.4.1`. O `vendor/composer/platform_check.php`, carregado no
*bootstrap* de toda requisição e de todo comando, aborta com

```
Composer detected issues in your platform: Your Composer dependencies require a
PHP version ">= 8.4.1". You are running 7.4.33.
```

em qualquer PHP anterior. A falha é **fechada** (500 imediato), nunca um ambiente
meio funcional — e é justamente ela que aparece no navegador quando o painel está
entregando uma versão antiga.

| Série | Suporte oficial (php.net, 04/11/2026) |
|---|---|
| 8.5 | bugs + segurança até 31/12/2027; segurança até 31/12/2029 |
| **8.4 — alvo do projeto** | bugs + segurança até 31/12/2026; segurança até 31/12/2028 |
| 8.3 | apenas segurança, até 31/12/2027 |
| 8.2 | apenas segurança, até 31/12/2026 |
| 8.1 e anteriores | **fim de vida** — nenhuma correção de segurança |

Painéis compartilhados costumam deixar `5.6`, `7.x` ou `8.1` como padrão, sem
patch de segurança há anos, e o valor pode ser diferente para o site e para o
cron. Como conferir sem SSH, usando o próprio cron do painel:

```
# comando do cron (uma execução só, depois remover):
php -r 'file_put_contents(__DIR__."/php-version.txt", PHP_VERSION." | ".PHP_SAPI.PHP_EOL);'
# e, para provar que o artisan sobe com essa versão:
php artisan about --only=environment >> php-version.txt
```

Leia o `php-version.txt` pelo gerenciador de arquivos; o esperado é `8.4.x` e um
bloco `Environment` sem `Composer detected issues`. No site, o teste equivalente é
abrir `/up` (deve responder 200) e, depois, o próprio bootstrap da aplicação.

---

## 2. Decisões de arquitetura

### 2.1 O modo de entrega é decidido no servidor

`app/Support/Realtime/RealtimeMode.php` concentra a regra:

| Condição | `RealtimeMode::current()` |
|---|---|
| `CONSULTATION_WEBSOCKET_ENABLED=false` | `poll` |
| `BROADCAST_CONNECTION` é `reverb`, `pusher` ou `ably` | `socket` |
| qualquer outro caso (`log`, `null`, valor vazio) | `poll` |

O valor é publicado no HTML como `data-realtime-mode="socket|poll"` em dois
pontos: `resources/views/consultations/partials/chat.blade.php` (chat e cartas)
e `resources/views/layouts/partials/realtime-alerts.blade.php` (painel, modal de
solicitação e sirene).

Por que no servidor e não em `VITE_*`:

- o bundle compilado é **o mesmo** para Docker e Hostoo — nada de build separado
  por ambiente nem risco de publicar assets apontando para um Reverb inexistente;
- o navegador nunca escolhe o transporte; ele apenas obedece ao atributo;
- trocar de transporte é uma variável de ambiente + `php artisan config:cache`,
  sem `npm run build`.

Os intervalos também vêm do servidor (`config/consultation.php` → bloco
`realtime.poll`) e são publicados como `data-poll-*`. O JavaScript lê os valores
com `readMilliseconds` (`resources/js/polling.js`), que descarta valor ausente,
zero ou inválido e cai no padrão seguro.

### 2.2 Dois modos, um só código de tela

| Aspecto | `socket` (Docker) | `poll` (Hostoo) |
|---|---|---|
| Mensagens novas | evento `ConsultationMessageSent` | `GET /consultas/{id}/mensagens?after_id=` a cada 2 s |
| Mudança de estado (aceite, encerramento) | evento `ConsultationStateChanged` | mesmo endpoint (campo `consultation`) + `GET /painel/eventos` |
| Fila de solicitações e sirene | canal `user.{id}` | `GET /painel/eventos` a cada 3 s |
| Rede de segurança | leitura a cada 30 s quando o canal não está `connected` | é o próprio mecanismo principal |

O chat usa `data-first-page="1"` como critério: páginas antigas do histórico não
iniciam leitura alguma (não há nada novo a receber). Em modo `socket`, a leitura
de 30 s **não roda** enquanto `chatState.connection === 'connected'`.

### 2.3 Regra de ouro: polling é somente leitura

Os endpoints de leitura **não escrevem nada**: não atualizam
`last_activity_at`/`client_last_activity_at`/`attendant_last_activity_at`, não
criam `consultation_presence_sessions`, não marcam mensagens como lidas e não
tocam carteira, cobrança ou comissão.

Atividade, presença e cobrança continuam com seus caminhos explícitos:

- presença: `POST /consultas/{id}/presenca` (batimento) e `.../presenca/sair`;
- atividade: abrir a tela, enviar mensagem/carta e interagir com o chat;
- cobrança: `consultations:charge-minutes`, com `DB::transaction` +
  `lockForUpdate` em `ConsultationBillingManager`.

Essa separação é o que impede que "manter a aba aberta" vire atividade (e
impeça o encerramento por inatividade) ou que uma leitura de 2 s gere lançamento
financeiro. O teste `tests/Feature/RealtimePollingTest.php` fixa esse contrato.

O batimento de presença não conta como atividade, mas passou a ser também o
ponto onde o prazo de inatividade é conferido (§6.1): quando ele vence, a
requisição de presença encerra a consulta e liquida os minutos uma única vez —
continua não sendo polling de leitura.

---

## 3. O que mudou no código

Nada foi removido: o Reverb continua instalado e configurado. As adições são
aditivas e desligadas por configuração.

| Arquivo | Mudança |
|---|---|
| `app/Support/Realtime/RealtimeMode.php` | **novo** — decide `socket`/`poll` a partir de `broadcasting.default` e `consultation.realtime.websocket_enabled` |
| `app/Http/Controllers/PanelEventsController.php` | **novo** — `GET /painel/eventos`, feed somente leitura do painel |
| `routes/web.php` | rota `panel.events` (autenticada, `throttle:panel-events`) e `throttle:consultation-feed` na leitura de mensagens |
| `app/Providers/AppServiceProvider.php` | limitadores `consultation-feed` e `panel-events` |
| `config/consultation.php` | bloco `realtime` (`websocket_enabled`, `poll.*`, limites por minuto) |
| `bootstrap/app.php` | `trustProxies(at: env('TRUSTED_PROXIES'))` — resolvido **antes** do Dotenv: o valor vem do servidor, não do `.env` (nota na seção 5) |
| `resources/js/polling.js` | **novo** — `createPoller` e `readMilliseconds` |
| `resources/js/chat.js` | usa o agendador; `syncMessages` devolve `ok`/`skipped`/`error`; para ao encerrar |
| `resources/js/user-channel.js` | usa o agendador; reconcilia fila e estado; abre modal e sirene |
| `resources/views/**/chat.blade.php` | publica `data-realtime-mode` e `data-poll-*` |
| `resources/views/layouts/partials/realtime-alerts.blade.php` | publica `data-events-url`, `data-realtime-mode` e `data-poll-*` |

### 3.1 `createPoller` — o ritmo das leituras

`resources/js/polling.js` é o único lugar que agenda requisições. Regras:

- encadeia `setTimeout` (nunca `setInterval`): ciclo lento não acumula chamadas;
- **nunca** duas leituras simultâneas: se a anterior não terminou, o ciclo apenas
  espera;
- aba oculta usa o intervalo maior; voltar para a aba lê imediatamente;
- `navigator.onLine === false` pausa as leituras até o evento `online`;
- falha (rede, 429, 5xx) dobra a espera até `max_backoff_ms` (6 dobramentos);
  um acerto volta ao ritmo normal;
- sorteio de ±15% espalha várias abas para não sincronizar tudo no mesmo segundo
  do servidor compartilhado;
- `stop()` cancela o timer e remove os listeners (chamado quando a consulta
  encerra ou a página deixa de ser a primeira do histórico).

### 3.2 `PanelEventsController` — o feed do painel

Devolve apenas o que pertence ao usuário autenticado:

```json
{
  "requests": [ { "id": 12, "status": "requested", "...": "..." } ],
  "consultation": { "id": 9, "status": "active", "is_final": false, "...": "..." },
  "realtime_mode": "poll"
}
```

- `requests` só é preenchido para **atendente** (`viewAttendantPanel`) e apenas
  com as solicitações `requested` dele;
- `consultation` é a consulta em aberto (`requested` ou `active`) em que o
  usuário é cliente ou atendente — para o cliente é o que revela o aceite;
- administrador recebe `403`: o layout administrativo não inclui o bloco de
  avisos, então ele não tem para onde entregar o payload;
- resposta com `Cache-Control: no-store, max-age=0` (nada de proxy/CDN
  devolvendo fila velha).

No navegador, `user-channel.js` reconcilia a fila: some com o que foi respondido
em outra aba, acrescenta o que é novo e — na primeira leitura de cada pedido —
abre o modal e arma a sirene. Aceitar/recusar continuam chamando as rotas do
servidor; o navegador não decide nada.

Na tela de conversa do **cliente**, o chat já lê o estado a cada 2 s, então o
poller do painel é suprimido para não duplicar requisições. Para o
**atendente** ele continua: é o que abre o modal de um pedido novo enquanto ele
está dentro de outro atendimento.

---

## 4. Contratos das leituras

### 4.1 `GET /painel/eventos` (`panel.events`)

| Item | Valor |
|---|---|
| Autenticação | `auth` (redireciona convidado para o login) |
| Autorização | `viewAttendantPanel` ou `viewClientPanel` (admin → 403) |
| Limite | `throttle:panel-events` — `CONSULTATION_PANEL_FEED_RATE_PER_MINUTE` (60/min) por usuário |
| Cache | `no-store` |
| Escritas | nenhuma |

### 4.2 `GET /consultas/{consulta}/mensagens?after_id=N` (`consultations.messages.index`)

Já existia como recuperação após reconexão; no Hostoo passa a ser o caminho
principal do chat.

| Item | Valor |
|---|---|
| Autorização | `viewMessages` (participante ativo) |
| Cursor | `after_id` (inteiro ≥ 0); devolve até 100 mensagens em ordem crescente |
| Payload | `messages`, `last_message_id`, `has_more`, `consultation` |
| Limite | `throttle:consultation-feed` — `CONSULTATION_MESSAGE_FEED_RATE_PER_MINUTE` (90/min) por participante |
| Escritas | nenhuma |

`after_id` é sempre o `last_message_id` anterior; o servidor nunca reenvia o que
o navegador já tem. O histórico persistido continua sendo a fonte de verdade —
um evento perdido apenas custa um ciclo de leitura.

### 4.3 Outras leituras que já existiam

- `GET /consultas/{id}/metricas` — tempo e cobrança da tela de atendimento;
- `GET /consultas/{id}/chamada` — estado e sinais WebRTC
  (`CONSULTATION_CALL_SIGNAL_POLL_MS`, 2500 ms).

As chamadas de áudio/vídeo **já** sinalizavam por polling; no Hostoo elas
funcionam igual, mas dependem de STUN/TURN acessível (ver seção 8).

---

## 5. Configuração do ambiente

Use [`.env.hostoo.example`](../.env.hostoo.example) como base. As variáveis que
**mudam** em relação ao Docker:

| Variável | Docker | Hostoo | Motivo |
|---|---|---|---|
| `APP_ENV` | `local` | `production` | bloqueia o seeder de demonstração e ativa cookies `secure` |
| `APP_DEBUG` | `true` | `false` | nunca expor stack trace ao público |
| `LOG_LEVEL` | `debug` | `warning` | log em arquivo pesa em disco compartilhado |
| `DB_HOST` | `mysql` | `localhost` | banco do painel, normalmente no mesmo servidor |
| `SESSION_DRIVER` | `redis` | `database` | sem Redis; tabela `sessions` já existe |
| `CACHE_STORE` | `redis` | `database` | tabela `cache`/`cache_locks` já existe |
| `QUEUE_CONNECTION` | `redis` | `database` | tabela `jobs` já existe |
| `BROADCAST_CONNECTION` | `reverb` | `log` | nenhum serviço WebSocket disponível |
| `CONSULTATION_WEBSOCKET_ENABLED` | `true` | `false` | publica `data-realtime-mode="poll"` |
| `TRUSTED_PROXIES` | *(vazio)* | injetado pelo servidor (`SetEnv`) | TLS termina no proxy do provedor; **não** funciona no `.env` |
| `REVERB_*` / `VITE_REVERB_*` | preenchidos | **removidos** | sem servidor Reverb |

Permanecem inalteradas: `APP_KEY`, `APP_URL`, limites de consulta, pagamentos,
`CONSULTATION_ICE_SERVERS` e `VITE_APP_NAME`.

> **`TRUSTED_PROXIES` não funciona no `.env`.** O `bootstrap/app.php` resolve esse
> valor com `env()` quando o kernel HTTP é construído — antes do bootstrap do
> Dotenv — e, com `config:cache`, o `.env` nem chega a ser carregado. O valor
> precisa ser injetado pelo servidor: `SetEnv TRUSTED_PROXIES "*"` no `.htaccess`
> (ou variável de ambiente do PHP no painel). Medição, consequências e as três
> correções possíveis na seção 9 de
> [TESTE_LOCAL_MODO_HOSTOO.md](TESTE_LOCAL_MODO_HOSTOO.md).
>
> **`"*"` é o mínimo para funcionar, não o ideal.** Com `TRUSTED_PROXIES="*"` o
> Laravel aceita `X-Forwarded-For`, `X-Forwarded-Host` e `X-Forwarded-Proto` de
> qualquer origem: um cliente consegue forjar o próprio IP (rate limit e
> auditoria) e o host usado na geração de URLs. Prefira a faixa do proxy do
> provedor — `SetEnv TRUSTED_PROXIES "200.1.2.3,10.0.0.0/8"` —, que o Hostoo
> informa no painel/suporte. E **não** use o atalho `private_ranges`: era
> justamente o que o advisory PKSA-nxjs-s6hn-vb6m explorava (endereços
> IPv4-mapped, 6to4, Teredo e NAT64 aceitos como proxy), corrigido em
> `symfony/http-foundation` 8.1.11 — versão já aplicada no `composer.lock`.

Ajuste fino dos intervalos (todos opcionais, os padrões já servem):

```dotenv
CONSULTATION_POLL_CHAT_MS=2000
CONSULTATION_POLL_CHAT_HIDDEN_MS=10000
CONSULTATION_POLL_PANEL_MS=3000
CONSULTATION_POLL_PANEL_HIDDEN_MS=15000
CONSULTATION_POLL_MAX_BACKOFF_MS=30000
CONSULTATION_MESSAGE_FEED_RATE_PER_MINUTE=90
CONSULTATION_PANEL_FEED_RATE_PER_MINUTE=60
```

Orçamento de requisições por participante a cada minuto:

| Tela | Leituras/min | Limite | Folga |
|---|---|---|---|
| chat do cliente (aba visível) | 30 | 90 | 3× |
| chat do atendente (aba visível) | 30 | 90 | 3× |
| painel do atendente | 20 | 60 | 3× |
| painel do cliente (fora da conversa) | 20 | 60 | 3× |

Se o provedor reclamar de carga, suba `CONSULTATION_POLL_CHAT_MS` para `3000` e
`CONSULTATION_POLL_CHAT_HIDDEN_MS` para `15000`; a percepção de "tempo real"
cai um pouco e a carga acompanha.

> **Atenção ao `config:cache`.** Depois de qualquer alteração no `.env` é
> obrigatório rodar `php artisan config:cache`; com cache antigo a aplicação
> continua com os valores anteriores (inclusive `BROADCAST_CONNECTION`).

---

## 6. Cron: as duas entradas obrigatórias

Sem worker permanente, a fila **só anda** quando alguém a drena. No painel do
Hostoo (Cron Jobs) cadastre:

```cron
* * * * * cd /home/USUARIO/arcana && php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /home/USUARIO/arcana && php artisan queue:work --stop-when-empty --max-time=50 --tries=3 >> storage/logs/queue.log 2>&1
```

Por que cada uma existe:

- `schedule:run` dispara as quatro tarefas agendadas (`routes/console.php`):
  `consultations:expire-requests`, `consultations:interrupt-disconnected`,
  `consultations:charge-minutes` e `consultations:close-stale-calls`;
- `queue:work --stop-when-empty` drena a fila `database` e encerra sozinho —
  `--max-time=50` impede que um job trave e seja morto no meio do minuto
  seguinte, `--tries=3` evita job envenenado em loop;
- o mutex do `withoutOverlapping` vale **10 minutos** (`routes/console.php`), não
  as 24 h do padrão: se o provedor matar o processo por `max_execution_time`, a
  rotina volta no minuto seguinte em vez de ficar bloqueada pelo dia inteiro. A
  não duplicação de dinheiro não depende do mutex e sim da chave idempotente por
  minuto em `ConsultationBillingManager`.

Sem a segunda linha, as notificações e demais jobs enfileirados **não executam**:
o agendador enfileira e nada consome. É a falha silenciosa mais provável nesta
arquitetura. Verifique com:

```bash
php artisan queue:failed        # deve ficar vazio
php artisan schedule:list       # confirma as tarefas registradas
```

Para checar se o cron está vivo, compare `storage/logs/queue.log` com o relógio
ou rode `SELECT COUNT(*) FROM jobs;` — fila que só cresce significa cron parado.

### 6.1 Rede de segurança: o encerramento por inatividade não depende do cron

`schedule:run` continua sendo a rotina oficial, mas a interrupção por inatividade
passou a valer também dentro da requisição
(`ConsultationLifecycleManager::interruptIfInactive`):

- **onde roda**: no batimento de presença (`consultations.presence.heartbeat`, a
  cada `CONSULTATION_ACTIVITY_PING_SECONDS`, 20 s no perfil do Hostoo) e na
  abertura da tela de atendimento (`GET` do workspace);
- **quando dispara**: vencido `CONSULTATION_INACTIVITY_TIMEOUT_MINUTES` +
  `CONSULTATION_RECONNECTION_TOLERANCE_SECONDS` sem interação real
  (`consultations.last_activity_at`). Leitura de polling não renova o relógio;
- **o que muda no banco**: status `interrupted`, motivo `inactivity`, sessões de
  presença fechadas, comissão provisionada e **minutos vencidos liquidados** na
  mesma transação (`settleBilling` → `billDue`, idempotente por minuto) — o valor
  final não fica pendente esperando cron;
- **o que a tela faz**: o `POST` volta `200` com `status`/`ended_reason` finais e
  `session_token: null`; o JS encerra os temporizadores e navega para o
  histórico. Não existe mais o `422` de "consulta não está ativa";
- **infraestrutura**: só MySQL. Os limites vêm de `settings` (a mesma tela
  `/admin/atendimento/seguranca`), lidos a cada requisição — nada de Redis, de
  Reverb ou de worker. O evento `ConsultationStateChanged` gerado ali fica na
  tabela `jobs` até o `queue:work` do cron drenar; o encerramento já está gravado
  e o outro lado o vê pelo polling.

O que **continua** exclusivo do cron (e por isso as duas linhas do §6 seguem
obrigatórias): cobrar o minuto *durante* a consulta
(`consultations:charge-minutes` — o polling não escreve em dinheiro, invariante
do §7), expirar solicitações e fechar chamadas sem resposta. Sem
`charge-minutes`, o cliente não é interrompido por saldo insuficiente no meio da
conversa; o encerramento cobra os minutos que o saldo cobre e os restantes ficam
registrados como não pagos na auditoria (`consultation.interrupted`), sem saldo
negativo.

A rede de segurança precisa de **um participante** voltando à tela: quem está
com o chat aberto derruba a consulta vencida do outro lado, e quem reabre a tela
derruba a própria. Se as duas partes fecharem o navegador e o cron estiver
parado, ninguém dispara a verificação e a consulta fica `active` no banco —
motivo pelo qual o `schedule:run` continua sendo o mecanismo oficial, monitorado
(§12, checklist de lançamento).

---

## 7. Integridade financeira no polling

Três garantias sustentam a cobrança neste modo:

1. **Leitura não cobra.** Enquanto a consulta está ativa, nenhum endpoint de
   polling escreve em `credit_ledger_entries`, `consultations.billed_minutes` ou
   `consultation_presence_sessions`. Só `consultations:charge-minutes` (cron), as
   ações explícitas do usuário e o **encerramento** alteram dinheiro — e o
   encerramento por inatividade é uma transição de estado única, decidida pelo
   servidor no batimento ou na abertura da tela (`interruptIfInactive`), que
   liquida os minutos vencidos uma vez (`billDue` é idempotente por minuto).
2. **Cobrança é idempotente por janela.** `ConsultationBillingManager` roda em
   `DB::transaction`, trava a consulta com `lockForUpdate`, avança o cursor
   `billed_minutes` e grava cada débito com a chave
   `consultation:{id}:minute:{n}`. Dois ciclos simultâneos (cron + acesso) não
   duplicam lançamento.
3. **Inatividade não é renovada pelo polling.** O encerramento automático olha
   `last_activity_at`, atualizado só por interação real. Uma aba aberta no
   Hostoo não segura a consulta indefinidamente — e, por causa do §6.1, quem a
   encerra pode ser o próprio batimento, sem depender do cron.

O teste `tests/Feature/RealtimePollingTest.php` cobre os três pontos: após uma
sequência de leituras, `last_activity_at`, `client_last_activity_at`,
`attendant_last_activity_at`, `billed_minutes`, as sessões de presença e o
extrato de créditos permanecem exatamente iguais. Já
`tests/Feature/ConsultationSafetyTest.php`
(`test_inactivity_interrupt_works_on_the_hostoo_profile_without_a_scheduler`)
exercita o §6.1 com os drivers do Hostoo — `cache`/`queue` no banco,
`broadcasting=log` e nenhum scheduler — e confirma consulta interrompida,
minutos liquidados e evento parado em `jobs`.

---

## 8. Limitações e riscos residuais

| Limitação | Impacto | Mitigação |
|---|---|---|
| Latência de 2–3 s | mensagem/estado aparece com atraso | intervalos configuráveis; aba oculta reduz carga |
| Uma requisição PHP por leitura | consome FPM do plano compartilhado | limites por minuto + backoff + `readMilliseconds` |
| Sem TURN próprio | chamada de áudio/vídeo pode falhar em 4G/NAT simétrico | configurar TURN em `CONSULTATION_ICE_SERVERS` |
| Fila depende do cron | atraso de até 60 s em jobs | `--max-time=50` mantém o ciclo curto |
| Cron compartilhado pode ser limitado | job interrompido no meio | jobs idempotentes + `--tries=3` + `queue:failed` monitorado |
| Cron parado ou não cadastrado | sem cobrança por minuto, sem expiração de pedidos, sem fim por saldo insuficiente | o encerramento por inatividade continua valendo na requisição (§6.1) e liquida os minutos vencidos; cadastrar as duas linhas do §6 e monitorar `jobs` |
| Sem Redis | cache/sessão no MySQL | volume baixo no MVP; índices já criados pelas migrations |
| `storage:link` manual | imagens de tarô não aparecem | rodar `php artisan storage:link` no deploy |
| Log em arquivo limitado | rotação pelo provedor | `LOG_LEVEL=warning`, revisar `storage/logs` |

O que **não** foi perdido: autorização por canal continua igual (as rotas de
leitura usam as mesmas policies), o isolamento entre clientes e atendentes é
mantido pelo `PanelEventsController`, e a sirene/modal de nova solicitação
continuam existindo.

---

## 9. Passo a passo do deploy

1. **Preparar o plano**: PHP **8.4** selecionado no painel — vale para o site e
   para cada entrada de cron; mínimo 8.4.1, pois abaixo disso o
   `platform_check` do Composer derruba a requisição (seção 1.1). MySQL 8,
   acesso SSH e Composer. Sem SSH, use o instalador do provedor e envie o
   `vendor/` do build local.

   ```bash
   php -v        # PHP 8.4.x
   php -r 'echo PHP_VERSION_ID >= 80401 ? "ok\n" : "ABORTAR\n";'
   ```
2. **Enviar o código** para `/home/USUARIO/arcana` (fora de `public_html`) e
   apontar o domínio para `public/`.
3. **Criar o `.env`** a partir de `.env.hostoo.example` e gerar a chave:

   ```bash
   cp .env.hostoo.example .env
   php artisan key:generate --force
   ```

4. **Instalar dependências** sem os pacotes de desenvolvimento:

   ```bash
   composer install --no-dev --optimize-autoloader --no-interaction
   ```

5. **Migrar** (nunca `migrate:fresh` em produção):

   ```bash
   php artisan migrate --force
   ```

6. **Linkar o storage**:

   ```bash
   php artisan storage:link
   ```

7. **Instalar o catálogo de baralhos** com o acervo enviado junto do código:

   ```bash
   php artisan arcana:import-tarot-catalog
   ```

   O comando lê `database/seeders/assets/tarot` (284 JPEGs, ~13 MB), cria os
   cinco baralhos e suas cartas pelo `slug` e copia as frentes para
   `storage/app/private/tarot/cards/`. É idempotente: pode ser reexecutado para
   reparar arquivos ausentes, nunca sobrescreve imagem enviada pela
   administração e não depende de `db:seed` — que permanece bloqueado em
   produção por ser dado de demonstração. Use `--source=/caminho/do/acervo` se
   as imagens forem enviadas para outro diretório.

8. **Compilar os assets** (localmente ou no servidor, se houver Node):

   ```bash
   npm ci && npm run build
   ```

   `public/build` **não** é versionado (`.gitignore`). Se o Hostoo não tiver Node,
   compile localmente e envie a pasta `public/build` junto do código; sem o
   manifest o site responde com "Unable to locate file in Vite manifest".

9. **Otimizar** — nesta ordem, para o cache refletir o `.env` final:

   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

10. **Cadastrar os dois crons** da seção 6 no painel do Hostoo.
11. **Criar o administrador** e o primeiro atendente:

    ```bash
    php artisan arcana:create-admin
    php artisan arcana:create-attendant --publish --commission-rate=30 --price-text=100
    ```

    Antes do primeiro aceite, cadastre os preços por minuto do atendente em
    **Admin → Modalidades** (`attendant_modality_prices`); sem eles o pedido e o
    aceite falham com "O preço desta modalidade ainda não foi configurado para
    este atendente". A ordem completa de bootstrap está em
    [DEPLOYMENT.md](DEPLOYMENT.md) seções 5 e 6.
12. **Validar** com a seção 10.

Depois de cada deploy:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

`config:cache` é obrigatório sempre que o `.env` mudar. `queue:restart` encerra
educadamente o worker em execução; o cron do minuto seguinte sobe um novo, já com
o código atualizado.

---

## 10. Verificação e rollback

### 10.1 Confirmar que o modo polling está ativo

```bash
php artisan config:show broadcasting.default        # log
php artisan config:show consultation.realtime
```

No navegador, abra o painel e confira no HTML:

```html
<div data-user-channel
     data-events-url="https://.../painel/eventos"
     data-realtime-mode="poll"
     data-poll-interval-ms="3000"
     data-poll-hidden-interval-ms="15000"
     data-poll-max-backoff-ms="30000" ...>
```

Se aparecer `data-realtime-mode="socket"` com `BROADCAST_CONNECTION=log`, o cache
de configuração está velho: rode `php artisan config:cache` novamente.

### 10.2 Teste funcional rápido

1. Duas abas: cliente e atendente. Cliente solicita consulta.
2. Em até ~3 s o atendente recebe o modal e a sirene.
3. Atendente aceita; o cliente vê a mudança de estado sem recarregar.
4. Mensagens aparecem nos dois lados em ~2 s.
5. Encerre a consulta e confirme `billed_minutes`, extrato e presença coerentes.

### 10.3 Diagnóstico de problemas

| Sintoma | Causa provável | Ação |
|---|---|---|
| Nada chega nas telas | `data-realtime-mode="socket"` + sem Reverb | `php artisan config:cache` |
| 500 com `Composer detected issues in your platform` | painel entregando PHP < 8.4.1 (site ou cron) | trocar a versão do PHP no painel e conferir com `php -v` (§1.1) |
| Erros 429 no console | intervalo abaixo do limite por minuto | subir `CONSULTATION_*_POLL_MS` ou o limite |
| 403 em `/painel/eventos` | usuário `admin` (sem bloco de avisos) | esperado; use conta de atendente/cliente |
| Login não persiste | cookie `secure` em HTTP ou proxy não reconhecido | HTTPS no proxy; `TRUSTED_PROXIES` via `SetEnv` no servidor (não no `.env`) |
| Jobs não rodam | cron de `queue:work` ausente | cadastrar a segunda linha da seção 6 |
| `withoutOverlapping` travado | execução anterior morreu no meio | `php artisan cache:clear` |

### 10.4 Rollback para WebSocket

Se o provedor passar a oferecer processo persistente: preencha `REVERB_*`,
`VITE_REVERB_*`, `BROADCAST_CONNECTION=reverb`,
`CONSULTATION_WEBSOCKET_ENABLED=true`, rode `npm run build`, suba o
`reverb:start` sob supervisor e execute `php artisan config:cache`. Nenhuma
migração ou reversão de dados é necessária — o polling continua existindo como
rede de segurança.

---

## 11. Testes automatizados

O comportamento deste modo é coberto por
`tests/Feature/RealtimePollingTest.php`:

| Cenário | Garantia |
|---|---|
| modo `poll` com `log`/`null` | `RealtimeMode::current()` responde `poll` |
| modo `socket` com `reverb`/`pusher`/`ably` | responde `socket` |
| flag `CONSULTATION_WEBSOCKET_ENABLED=false` | força `poll` mesmo com `BROADCAST_CONNECTION=reverb` |
| atendente | recebe só as próprias solicitações |
| cliente | recebe `requests` vazio e o estado da consulta aberta |
| administrador | 403 |
| convidado | redirecionado ao login |
| leitura repetida | `last_activity_at`, `client_last_activity_at`, `attendant_last_activity_at`, `billed_minutes`, presença e créditos inalterados |
| abuso | 429 ao exceder o limite configurado |

O encerramento por inatividade sem cron tem testes próprios em
`tests/Feature/ConsultationSafetyTest.php`:

| Cenário | Garantia |
|---|---|
| batimento depois do prazo (5 min + 90 s) | consulta `interrupted`/`inactivity`, sessão fechada, auditoria única |
| reabrir a tela depois do prazo | redireciona ao histórico com aviso, sem abrir o chat |
| perfil do Hostoo (`cache`/`queue` no banco, `broadcasting=log`, sem scheduler) | interrupção e liquidação dos minutos na própria requisição; evento apenas enfileirado em `jobs` |

Execução (o host de desenvolvimento não tem `pdo_sqlite`, então use o container):

```bash
docker compose exec -T app php artisan test --compact tests/Feature/RealtimePollingTest.php
docker compose exec -T app php artisan test --compact \
    tests/Feature/ConsultationRealtimeTest.php \
    tests/Feature/ConsultationChatTest.php \
    tests/Feature/ConsultationSafetyTest.php
```

---

## 12. Checklist de lançamento no Hostoo

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` gerada
- [ ] `BROADCAST_CONNECTION=log` e `CONSULTATION_WEBSOCKET_ENABLED=false`
- [ ] `SESSION_DRIVER`/`CACHE_STORE`/`QUEUE_CONNECTION` = `database`
- [ ] nenhuma variável `REVERB_*`/`VITE_REVERB_*` no `.env`
- [ ] `SetEnv TRUSTED_PROXIES "*"` no servidor (o `.env` não é lido nesse ponto) e HTTPS no proxy
- [ ] PHP **8.4.x** (≥ 8.4.1) selecionado no painel para o site **e** para os crons
- [ ] `composer install --no-dev` concluído sem `Composer detected issues in your platform`
- [ ] `composer audit --no-dev` sem advisories em aberto
- [ ] `composer install --no-dev` + `npm run build` aplicados
- [ ] `migrate --force` executado e `storage:link` criado
- [ ] `config:cache`, `route:cache`, `view:cache` após o `.env` final
- [ ] os **dois** crons cadastrados e com log recente
- [ ] `php artisan schedule:list` mostra as quatro tarefas
- [ ] HTML entrega `data-realtime-mode="poll"`
- [ ] teste funcional das duas abas (solicitação → aceite → mensagens)
- [ ] `php artisan queue:failed` vazio após 24 h
- [ ] backups do MySQL agendados e restauração testada

---

## Referências

- [REALTIME.md](REALTIME.md) — Reverb, canais e eventos no ambiente Docker.
- [DEPLOYMENT.md](DEPLOYMENT.md) — runbook completo, backups e checklist geral.
- [`../.env.hostoo.example`](../.env.hostoo.example) — modelo de `.env` para
  hospedagem compartilhada.





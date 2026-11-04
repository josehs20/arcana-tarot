# Arcana Tarot — Arquitetura de produção no Hostoo

> Runbook da implantação em **hospedagem compartilhada (Hostoo)** — ambiente sem
> Redis, sem Reverb e sem worker permanente — mantendo Docker/Reverb intacto para
> o desenvolvimento local.
> Complementa [REALTIME.md](REALTIME.md) (WebSocket com Reverb) e
> [DEPLOYMENT.md](DEPLOYMENT.md) (runbook Docker).

---

## 1. Diagnóstico: o que o Hostoo não oferece

O projeto foi construído no Docker Compose, com seis serviços. Numa hospedagem
compartilhada, quatro deles não têm equivalente:

| Requisito do projeto | Como funciona no Docker | No Hostoo | Consequência |
|---|---|---|---|
| Servidor WebSocket (`laravel/reverb`) | serviço `reverb` + `php artisan reverb:start` | **não existe** processo persistente | sem canal `user.{id}` e `consultation.{id}` |
| Worker de fila | serviço `queue` (`queue:work redis`) | **não existe** | jobs só rodam quando o cron chama `queue:work` |
| Redis (fila/cache) | serviço `redis:7` | **não existe** | fila, cache e sessão precisam do MySQL |
| Scheduler | cron na máquina de operação | existe, via painel | precisa de duas entradas de cron |
| Nginx/PHP-FPM próprios | serviços `nginx` + `app` | gerenciados pelo provedor | document root aponta para `public/` |
| TLS | proxy do desenvolvedor | termina no proxy do provedor | `X-Forwarded-*` e cookies `secure` |

Sem WebSocket e sem worker, o caminho "evento → fila → Reverb → navegador" não
fecha. Foi exatamente essa a mudança de arquitetura: **o navegador passa a ler o
estado por HTTP em intervalos curtos**, e as rotinas financeiras são acionadas
pelo cron.

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
| `bootstrap/app.php` | `trustProxies(at: env('TRUSTED_PROXIES'))` |
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
| `TRUSTED_PROXIES` | *(vazio)* | `*` | TLS termina no proxy do provedor |
| `REVERB_*` / `VITE_REVERB_*` | preenchidos | **removidos** | sem servidor Reverb |

Permanecem inalteradas: `APP_KEY`, `APP_URL`, limites de consulta, pagamentos,
`CONSULTATION_ICE_SERVERS` e `VITE_APP_NAME`.

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
  seguinte, `--tries=3` evita job envenenado em loop.

Sem a segunda linha, as notificações e demais jobs enfileirados **não executam**:
o agendador enfileira e nada consome. É a falha silenciosa mais provável nesta
arquitetura. Verifique com:

```bash
php artisan queue:failed        # deve ficar vazio
php artisan schedule:list       # confirma as tarefas registradas
```

Para checar se o cron está vivo, compare `storage/logs/queue.log` com o relógio
ou rode `SELECT COUNT(*) FROM jobs;` — fila que só cresce significa cron parado.

---

## 7. Integridade financeira no polling

Três garantias sustentam a cobrança neste modo:

1. **Leitura não cobra.** Nenhum endpoint de polling escreve em
   `credit_ledger_entries`, `consultations.billed_minutes` ou
   `consultation_presence_sessions`. Só `consultations:charge-minutes` (cron) e
   as ações explícitas do usuário alteram dinheiro.
2. **Cobrança é idempotente por janela.** `ConsultationBillingManager` roda em
   `DB::transaction`, trava a consulta com `lockForUpdate`, avança o cursor
   `billed_minutes` e grava cada débito com a chave
   `consultation:{id}:minute:{n}`. Dois ciclos simultâneos (cron + acesso) não
   duplicam lançamento.
3. **Inatividade não é renovada pelo polling.** O encerramento automático olha
   `last_activity_at`, atualizado só por interação real. Uma aba aberta no
   Hostoo não segura a consulta indefinidamente.

O teste `tests/Feature/RealtimePollingTest.php` cobre os três pontos: após uma
sequência de leituras, `last_activity_at`, `client_last_activity_at`,
`attendant_last_activity_at`, `billed_minutes`, as sessões de presença e o
extrato de créditos permanecem exatamente iguais.

---

## 8. Limitações e riscos residuais

| Limitação | Impacto | Mitigação |
|---|---|---|
| Latência de 2–3 s | mensagem/estado aparece com atraso | intervalos configuráveis; aba oculta reduz carga |
| Uma requisição PHP por leitura | consome FPM do plano compartilhado | limites por minuto + backoff + `readMilliseconds` |
| Sem TURN próprio | chamada de áudio/vídeo pode falhar em 4G/NAT simétrico | configurar TURN em `CONSULTATION_ICE_SERVERS` |
| Fila depende do cron | atraso de até 60 s em jobs | `--max-time=50` mantém o ciclo curto |
| Cron compartilhado pode ser limitado | job interrompido no meio | jobs idempotentes + `--tries=3` + `queue:failed` monitorado |
| Sem Redis | cache/sessão no MySQL | volume baixo no MVP; índices já criados pelas migrations |
| `storage:link` manual | imagens de tarô não aparecem | rodar `php artisan storage:link` no deploy |
| Log em arquivo limitado | rotação pelo provedor | `LOG_LEVEL=warning`, revisar `storage/logs` |

O que **não** foi perdido: autorização por canal continua igual (as rotas de
leitura usam as mesmas policies), o isolamento entre clientes e atendentes é
mantido pelo `PanelEventsController`, e a sirene/modal de nova solicitação
continuam existindo.

---

## 9. Passo a passo do deploy

1. **Preparar o plano**: PHP 8.4 (ou o disponível ≥ 8.4 no painel), MySQL 8,
   acesso SSH e Composer. Sem SSH, use o instalador do provedor e envie o
   `vendor/` do build local.
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

   O comando lê `database/seeders/assets/tarot` (114 JPEGs, ~6 MB), cria os
   dois baralhos e suas cartas pelo `slug` e copia as frentes para
   `storage/app/private/tarot/cards/`. É idempotente: pode ser reexecutado para
   reparar arquivos ausentes, nunca sobrescreve imagem enviada pela
   administração e não depende de `db:seed` — que permanece bloqueado em
   produção por ser dado de demonstração. Use `--source=/caminho/do/acervo` se
   as imagens forem enviadas para outro diretório.

8. **Compilar os assets** (localmente ou no servidor, se houver Node):

   ```bash
   npm ci && npm run build
   ```

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
    php artisan arcana:create-attendant --publish --commission-rate=30
    ```

    As tarifas de `admin/financeiro` precisam existir antes do primeiro aceite;
    sem elas o aceite falha com "A tarifa desta modalidade ainda não está
    configurada". A ordem completa de bootstrap está em
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
| Erros 429 no console | intervalo abaixo do limite por minuto | subir `CONSULTATION_*_POLL_MS` ou o limite |
| 403 em `/painel/eventos` | usuário `admin` (sem bloco de avisos) | esperado; use conta de atendente/cliente |
| Login não persiste | `TRUSTED_PROXIES` vazio ou cookie `secure` em HTTP | `TRUSTED_PROXIES=*` e HTTPS no proxy |
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
- [ ] `TRUSTED_PROXIES=*` e HTTPS forçado no proxy
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





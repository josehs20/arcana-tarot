# Arcana Tarot — Tempo real com Laravel Reverb e Echo

> **Hospedagem compartilhada (Hostoo).** O Reverb precisa de um processo
> persistente, que não existe em planos compartilhados. Nesses ambientes a
> aplicação publica `data-realtime-mode="poll"` e as telas leem
> `GET /painel/eventos` e `GET /consultas/{id}/mensagens` em intervalos curtos.
> Nada aqui é removido: o modo é escolhido por `RealtimeMode::current()` a partir
> de `BROADCAST_CONNECTION` e `CONSULTATION_WEBSOCKET_ENABLED`. Guia completo em
> [ARQUITETURA_PRODUCAO_HOSTOO.md](ARQUITETURA_PRODUCAO_HOSTOO.md).

> Documento do **Prompt 12 — Reverb e eventos em tempo real**.
> Cobre versões, configuração, canais privados, contratos de eventos,
> reconexão/fallback, processos de desenvolvimento/produção e limitações de
> teste.

---

## 1. Versões e compatibilidade

Versões verificadas no ambiente Docker do projeto:

| Componente | Versão | Observação |
|---|---|---|
| PHP | 8.4.26 | container `app` |
| Laravel Framework | 13.38.0 | `laravel/framework` |
| Laravel Reverb | 1.12.0 | servidor WebSocket |
| Laravel Echo | 2.6.1 | cliente JS |
| pusher-js | 8.6.0 | transporte compatível com protocolo Pusher |
| Node.js | 22.22.1 | build Vite |
| Redis | 7 | filas e cache; opcional para scaling do Reverb |

O Reverb 1.12 declara suporte a `illuminate/* ^13.0`, portanto é compatível
com o Laravel 13 instalado. A instalação exigiu atualização de dependências
transitivas do Guzzle (`composer require laravel/reverb -W`) porque o lock do
projeto fixava uma major incompatível com `guzzlehttp/psr7 ^2.6`.

### Instalação aplicada

```bash
composer require laravel/reverb --with-all-dependencies
php artisan install:broadcasting --reverb --without-node
php artisan vendor:publish --tag=reverb-config
npm install --save laravel-echo pusher-js
npm run build
```

O `install:broadcasting` registrou `routes/channels.php` em
`bootstrap/app.php`, publicou `config/broadcasting.php` e criou o esqueleto de
`resources/js/echo.js`. O `vendor:publish` adicionou `config/reverb.php`.

---

## 2. Configuração

### 2.1 Variáveis de ambiente

Nenhum segredo real é versionado. O `.env.example` deixa `REVERB_APP_SECRET`
vazio; no ambiente local, gere com:

```bash
openssl rand -hex 32
```

| Variável | Exemplo local | Uso |
|---|---|---|
| `BROADCAST_CONNECTION` | `reverb` | broadcaster padrão |
| `REVERB_APP_ID` | `arcana` | identificador da aplicação no Reverb |
| `REVERB_APP_KEY` | `arcana_reverb_key` | chave pública usada pelo navegador |
| `REVERB_APP_SECRET` | **(segredo local)** | assinatura servidor→Reverb e autorização de canais |
| `REVERB_HOST` | `reverb` | host interno usado pelo servidor Laravel |
| `REVERB_PORT` | `8080` | porta pública/interna do Reverb |
| `REVERB_SCHEME` | `http` | `https` em produção |
| `REVERB_SERVER_HOST` | `0.0.0.0` | interface em que o processo Reverb escuta |
| `REVERB_SERVER_PORT` | `8080` | porta de escuta do processo |
| `REVERB_ALLOWED_ORIGINS` | `${APP_URL},127.0.0.1` | hosts autorizados a abrir WebSocket (aceita URL completa: o `config/reverb.php` reduz a host) |
| `VITE_REVERB_APP_KEY` | `${REVERB_APP_KEY}` | chave pública exposta ao frontend |
| `VITE_REVERB_HOST` | `localhost` | host visto pelo navegador |
| `VITE_REVERB_PORT` | `8080` | porta vista pelo navegador |
| `VITE_REVERB_SCHEME` | `http` | esquema visto pelo navegador |

`REVERB_APP_KEY` é pública por definição. `REVERB_APP_SECRET` **nunca** deve ir
para variáveis `VITE_*`, JavaScript, logs ou repositório.

O Reverb valida a origem do WebSocket comparando **apenas o host** da
requisição (`parse_url($origin, PHP_URL_HOST)` em
`Protocols/Pusher/Server::verifyOrigin()`). Por isso `config/reverb.php`
normaliza `REVERB_ALLOWED_ORIGINS` para hosts: `http://localhost:8000`,
`localhost:8000` e `localhost` chegam ao mesmo resultado, e curingas como
`*.arcana.test` são preservados. Listar a URL completa sem essa normalização
faz o servidor aceitar o upgrade (`101`) e derrubar a conexão em seguida com
`4009 Origin not allowed` — nenhum evento chega ao navegador. `127.0.0.1` está
na lista local porque o painel também pode ser aberto por
`http://127.0.0.1:8000`. Depois de alterar essa variável, **reinicie o Reverb**
(`docker compose restart reverb` ou `php artisan reverb:restart`): o processo lê
a configuração apenas no boot.

### 2.2 Arquivos

- `config/broadcasting.php` — conexão `reverb` e fallback `null`/`log`.
- `config/reverb.php` — servidor, aplicação, origens, ping e scaling.
- `routes/channels.php` — autorização do canal privado por consulta.
- `resources/js/echo.js` — criação preguiçosa do Echo e endpoint
  `/broadcasting/auth`.
- `resources/js/chat.js` — assinatura, atualização incremental, deduplicação,
  reconexão e fallback HTTP.

---

## 3. Processos e portas

### Desenvolvimento (Docker Compose)

| Serviço | Processo | Porta publicada | Função |
|---|---|---|---|
| `nginx` | Nginx | `8000` | HTTP da aplicação |
| `app` | PHP-FPM | interna `9000` | Laravel/HTTP |
| `queue` | `php artisan queue:work redis` | — | processa jobs de broadcast |
| `reverb` | `php artisan reverb:start` (`REVERB_SERVER_HOST/PORT`) | `8080` | WebSocket e API interna |
| `mysql` | MySQL 8.4 | interna `3306` | persistência |
| `redis` | Redis 7 | interna `6379` | filas/cache |

O navegador acessa `http://localhost:8000` e abre WebSocket em
`ws://localhost:8080`. O Laravel acessa o Reverb em `http://reverb:8080`.
Subir tudo:

```bash
docker compose up -d --build
docker compose ps
docker compose logs -f reverb queue
```

O serviço `reverb` não usa mais profile opcional; ele sobe junto com a stack.
O worker de filas é obrigatório: os eventos implementam `ShouldBroadcast` e
são enfileirados para não atrasar nem falhar a requisição HTTP que persistiu a
mensagem.

### Produção

- Execute o Reverb atrás de proxy reverso com TLS (`wss://`), mantendo o
  processo escutando em `0.0.0.0:8080` apenas na rede interna.
- Configure `REVERB_HOST` com o hostname interno do serviço, `REVERB_PORT=443`
  e `REVERB_SCHEME=https` para o tráfego servidor→Reverb pelo proxy.
- Ajuste `VITE_REVERB_HOST`, `VITE_REVERB_PORT=443` e
  `VITE_REVERB_SCHEME=https` antes do build do frontend.
- Restrinja `REVERB_ALLOWED_ORIGINS` ao domínio público da aplicação.
- Mantenha `queue:work` e `reverb:start` sob supervisor/systemd/container
  orchestrator, com reinício automático. Após deploy, reinicie ambos:

```bash
php artisan queue:restart
php artisan reverb:restart
```

- Para mais de uma instância do Reverb, habilite
  `REVERB_SCALING_ENABLED=true` e compartilhe Redis entre as instâncias.
  O MVP usa uma instância.
- Healthcheck: o Reverb não expõe `/up`; verifique o processo e a porta
  (`0.0.0.0:8080`). O `nginx` continua expondo `/up` para o Laravel.

#### Hospedagem compartilhada (sem processo persistente)

Quando o provedor não permite `reverb:start` nem `queue:work` permanentes, o
transporte muda para polling — mesmo código, mesmo bundle:

```dotenv
BROADCAST_CONNECTION=log
CONSULTATION_WEBSOCKET_ENABLED=false
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```

A fila passa a ser drenada por cron (`queue:work --stop-when-empty --max-time=50`)
e as telas leem `GET /painel/eventos` a cada 3 s e
`GET /consultas/{id}/mensagens?after_id=` a cada 2 s. Nenhum `REVERB_*` é
necessário. Passo a passo, limites e diagnóstico em
[ARQUITETURA_PRODUCAO_HOSTOO.md](ARQUITETURA_PRODUCAO_HOSTOO.md).


---

## 4. Canais privados e autorização

### 4.1 Canal da consulta

```text
private-consultation.{consultation_id}
```

`routes/channels.php` autoriza somente:

- usuário com `is_active = true`;
- cliente (`client_id`) ou atendente (`attendant_id`) da consulta;
- consultas em qualquer estado, inclusive finais, para leitura do histórico.

Terceiros, usuários inativos e administradores recebem HTTP 403 em
`POST /broadcasting/auth`. O admin continua sem acesso à conversa (D-023 e
Q-06); uma permissão administrativa explícita exigiria nova decisão, auditoria
e um canal separado.

### 4.2 Canal do usuário

```text
private-user.{user_id}
```

Canal pessoal, sem vínculo com uma consulta. Serve para avisos que valem para
a conta inteira, como a chegada de uma nova solicitação de atendimento no
painel do atendente. Somente o próprio usuário ativo recebe autorização;
qualquer outro usuário (inclusive admin) recebe 403.

O bloco que registra o canal fica em
`resources/views/layouts/partials/realtime-alerts.blade.php`, incluído pelo
layout `x-layouts.panel` em **toda tela de cliente e de atendente** (nunca na
administração). Assim o modal de aceite/recusa e o som de chamada acompanham o
atendente onde ele estiver — painel, histórico, detalhe da consulta ou tela de
atendimento — e não apenas no dashboard. Nas telas do cliente o mesmo bloco
serve para levá-lo à tela de atendimento quando o aceite acontece.

> O curinga se chama `userId` de propósito. Com um curinga chamado `user`, o
> `Broadcaster` do Laravel aplicaria binding implícito do modelo no parâmetro
> autenticado `$user` e a autorização seria negada com 403.

O cliente autentica os canais via sessão web em `/broadcasting/auth`. O Echo
envia o token CSRF e `X-Requested-With`; o endpoint usa o middleware `web` e
não usa canal público.

---

## 5. Eventos

### 5.1 `ConsultationMessageSent` → `consultation.message`

Emitido após o commit da mensagem textual ou de carta. O payload é produzido
por `ConsultationMessagePresenter` e contém apenas:

```json
{
  "message": {
    "id": 123,
    "consultation_id": 45,
    "sender_id": 7,
    "sender_name": "Nome público",
    "sender_role": "attendant",
    "sender_role_label": "Atendente",
    "type": "tarot_card",
    "body": null,
    "tarot_card_id": 9,
    "card_name": "A Estrela",
    "deck_name": "Baralho Solar",
    "image_url": "https://app.local/consultas/45/mensagens/123/imagem",
    "created_at": "2026-10-21T15:00:00-03:00",
    "created_at_label": "21/10/2026 15:00"
  }
}
```

`image_url` é uma rota autorizada; o payload **nunca** inclui `image_path`, nome
físico, diretório privado ou credenciais. O destinatário baixa a imagem por
`GET /consultas/{id}/mensagens/{message}/imagem`, que revalida a participação.

### 5.2 `ConsultationStateChanged` → `consultation.state`

Emitido somente quando o status realmente muda, depois do commit. Não há evento
em operações idempotentes (aceitar/encerrar repetidamente).

```json
{
  "consultation": {
    "id": 45,
    "status": "active",
    "status_label": "Em andamento",
    "status_dot": "online",
    "allows_messaging": true,
    "is_final": false,
    "ended_reason": null,
    "ended_reason_label": null,
    "started_at": "2026-10-21T15:00:00-03:00",
    "ended_at": null,
    "updated_at": "2026-10-21T15:00:00-03:00"
  }
}
```

`ConsultationStateChanged` sai pelo canal da consulta **e** pelo canal privado
de cada participante (`private-user.{id}`), para avisar quem está em outra tela
do painel. É esse fan-out que permite abrir a tela de atendimento
automaticamente assim que o aceite acontece.

O frontend atualiza os fragmentos `#chat`, `#consultation-progress`,
`#consultation-actions` e `#consultation-status-summary` via HTTP, sem recarregar
a página inteira, e navega conforme o novo estado:

| Situação | Cliente | Atendente |
|---|---|---|
| Solicitação aceita (`active`) | vai para `consultations.workspace` | vai para `consultations.workspace` |
| Recusada/cancelada | permanece na tela da consulta com o aviso | modal de solicitação fecha e a fila recarrega |
| Encerrada/interrompida | tela da consulta (histórico e recibo) | painel do atendente |


---

## 6. Persistência, idempotência e reconexão

- O banco é gravado **antes** do evento. Eventos usam
  `ShouldDispatchAfterCommit`; os managers também só anunciam depois que a
  transação retorna. A confirmação WebSocket nunca é prova de persistência.
- Falha de fila/broadcast é registrada em log e não desfaz a mensagem já
  persistida.
- O navegador deduplica por `data-message-id`; um evento repetido não cria
  outro item.
- Se chegar um evento com ID não contíguo, o cliente busca no servidor todas as
  mensagens após o último ID renderizado.
- Ao reconectar (`state_change = connected`), ao voltar para a aba, ao
  recuperar a rede ou a cada 30 segundos, o cliente consulta
  `GET /consultas/{id}/mensagens?after_id={último_id}`.
- A resposta inclui mensagens em ordem crescente, `has_more` e o estado atual.
  Se o status divergir do DOM, os fragmentos são atualizados.
- O endpoint de histórico usa `viewMessages`; admin e terceiros recebem 403.
- Páginas antigas da paginação não recebem append de mensagens; a interface
  orienta voltar à página mais recente.

`POST /consultas/{id}/mensagens/lidas` marca a leitura após uma mensagem
recebida em tempo real, mantendo o indicador de novas mensagens coerente.

---

## 7. Testes e limitação do ambiente

`tests/Feature/ConsultationRealtimeTest.php` cobre:

- autorização do canal privado para cliente/atendente ativos, e 403 para
  terceiros, admin e participante inativo;
- persistência antes do evento e payload textual seguro;
- evento de carta com `image_url` autorizada e sem caminho privado;
- `GET` da imagem por participante e 403 para terceiro;
- recuperação incremental por `after_id`, estado e bloqueio de terceiros/admin;
- endpoint de leitura e limpeza de não lidas;
- evento de estado após commit e ausência de evento em no-op;
- `allowed_origins` do Reverb reduzidas a **hosts** (é o único dado comparado
  na validação da origem) e normalização de URL completa, `host:porta` e
  curingas.

A conexão WebSocket fim-a-fim **não** é automatizada nesta suíte: exigiria um
navegador/headless mantendo sessão autenticada, autorização de canal e espera
por entrega. A cobertura automatizada valida as duas pontas críticas:
autorização HTTP do canal, contrato/emissão dos eventos e a configuração de
origem que o Reverb usa para aceitar a conexão; o build Vite valida o bundle do
Echo/Pusher. Para conferir a conectividade sem navegador, veja o roteiro de
diagnóstico na §10.

`tests/Feature/ConsultationWorkspaceTest.php` cobre a tela dedicada e os
avisos pessoais:

- números renderizados na tela e no endpoint de métricas para os dois papéis,
  com a comissão oculta do cliente;
- redirecionamento de quem abre a tela fora do estado `active` e bloqueio de
  terceiros (admin volta para a tela da consulta);
- fila de solicitações em ordem de chegada, com URLs de decisão e exclusividade
  do painel do atendente;
- autorização dos canais `private-consultation.{id}` e `private-user.{id}`,
  incluindo 403 para terceiro, admin e conta inativa;
- aceite devolvendo `redirect` para a tela de atendimento e o
  `ConsultationStateChanged` nos três canais (consulta + os dois usuários);
- dois aceites quase simultâneos: apenas um fica ativo e apenas uma cobrança é
  registrada;
- mensagem instantânea com JSON (`201` + payload persistido) e fallback de
  formulário HTML;
- recarga da tela restaurando o histórico em ordem cronológica e limpando o
  aviso de mensagens novas;
- modal de aceite/recusa e ganchos do canal `private-user.{id}` presentes em
  todas as telas do atendente (painel, histórico, detalhe e atendimento), e
  ausentes no painel do cliente e no da administração.

Teste manual recomendado:

1. Abrir duas sessões (cliente e atendente) em `http://localhost:8000`.
2. Aceitar a consulta e confirmar que o chat é liberado sem reload.
3. Enviar texto e carta pelos dois lados e observar o append imediato.
4. Derrubar/levantar o container `reverb`, confirmar “Reconectando…” e a
   recuperação de mensagens perdidas após reconectar.
5. Encerrar a consulta e confirmar modo somente leitura e ações atualizadas.
6. Deixar o atendente em `attendant/historico` (não no painel) e pedir um
   atendimento pelo cliente: o modal de aceite/recusa precisa abrir ali mesmo,
   com o som configurado; aceitar e confirmar o redirecionamento para a tela de
   atendimento dos dois lados.

---

## 8. Segurança e privacidade

- Nenhum canal público é usado para conversa.
- O canal privado revalida participação no servidor a cada autorização.
- Payloads não contêm caminho físico, segredo, e-mail ou dados financeiros.
- Imagens continuam em disco privado e são servidas por rota autorizada com
  `Cache-Control: private`.
- Texto é renderizado com `textContent` no JavaScript e escaping no Blade.
- Logs de aplicação não incluem conteúdo de mensagens.

---

## 9. Tela dedicada de atendimento

Depois do aceite, cliente e atendente trabalham em uma tela focada, sem o
restante do painel. Os números exibidos vêm sempre do servidor.

### 9.1 Rotas

| Rota | Nome | Função |
|---|---|---|
| `GET /consultas/{id}/atendimento` | `consultations.workspace` | Blade `consultations/workspace.blade.php`; redireciona para a tela da consulta quando o estado não é `active` |
| `GET /consultas/{id}/metricas` | `consultations.metrics` | JSON com minutos cobrados, total, comissão e próximo refresh |
| `GET /attendant/solicitacoes` | `attendant.requests.pending` | fila de solicitações do atendente autenticado |
| `POST /consultas/{id}/mensagens` | `consultations.messages.store` | envia mensagem; responde `201` em JSON ou redireciona para `#chat` |
| `POST /consultas/{id}/cartas` | `consultations.cards.store` | envia uma carta do Tarot com snapshot da imagem |
| `PATCH /preferencias/chat` | `preferences.chat.update` | grava `send_on_enter` e `sound_enabled` do usuário autenticado |

### 9.2 Contrato de `consultations.metrics`

`ConsultationMetricsPresenter` calcula tudo com os snapshots gravados no
aceite (`price_per_minute_cents`, `credit_unit_price_cents`,
`commission_rate_bps`), nunca com o preço atual do atendente:

```json
{
  "consultation_id": 45,
  "status": "active",
  "status_label": "Em andamento",
  "requested_at": "2026-10-21T15:00:00-03:00",
  "requested_at_label": "21/10/2026 15:00",
  "started_at": "2026-10-21T15:00:10-03:00",
  "ended_at": null,
  "waiting_seconds": 10,
  "elapsed_seconds": 45,
  "elapsed_label": "00:00:45",
  "billed_minutes": 2,
  "price_per_minute_cents": 300,
  "price_per_minute_label": "R$ 3,00",
  "charged_cents": 600,
  "charged_label": "R$ 6,00",
  "charged_explanation": "2 minutos iniciados",
  "commission_cents": 180,
  "commission_label": "R$ 1,80",
  "commission_rate_label": "30%",
  "next_refresh_in_seconds": 15,
  "updated_at": "2026-10-21T15:00:55-03:00"
}
```

`commission_cents`, `commission_label` e `commission_rate_label` são `null`
para o cliente; o servidor não confia no papel declarado pelo navegador, e sim
em `Gate::authorize('view', $consultation)` + participação.

### 9.3 Comportamento no navegador

- `resources/js/consultation-workspace.js` preenche `[data-metric]`, o
  contador `[data-consultation-timer]` e reagenda a próxima leitura de métricas
  em `next_refresh_in_seconds` (sempre no limite do minuto).
- `resources/js/user-channel.js` usa o canal `private-user.{id}`: escuta
  `consultation.state` para abrir a tela de atendimento e mantém `GET
  /attendant/solicitacoes` como fonte da fila a cada (re)conexão, aba visível e
  troca de estado — não é polling.
- `resources/js/chat.js` continua responsável pelo feed incremental
  (`after_id`), deduplicação e fallback HTTP.
- O aceite responde JSON com `redirect` para `consultations.workspace`; sem
  JavaScript, o mesmo fluxo devolve um redirect HTTP 302.

### 9.4 Aviso de nova solicitação: modal e som de chamada

Quando um cliente solicita um atendimento, o servidor emite
`ConsultationRequested` no canal `private-user.{id}` do atendente. No navegador,
`resources/js/user-channel.js`:

1. registra o pedido em memória (independente de existir fila na tela atual);
2. toca o **som de chamada configurado para o atendente** e o repete a cada
   2,4 s por até 90 s;
3. abre o modal `[data-request-modal]` com nome do cliente, modalidade, preço
   por minuto, horário e os botões **Aceitar atendimento** / **Recusar**.

O toque é escolhido em **Admin → Atendentes**, por atendente, entre `siren`
(padrão), `bell`, `chime`, `pulse` e `custom` (MP3 enviado pela administração,
com até 5 segundos e 1 MB). A alteração de um toque predefinido é auditada em
`attendant.request_sound_updated`; upload e remoção do MP3 usam
`attendant.request_sound_file_uploaded` e `attendant.request_sound_file_removed`.
O valor chega ao navegador por `data-request-sound` e, no caso de `custom`, a URL
pública do arquivo por `data-request-sound-url`, ambos no bloco
`[data-user-channel]`. O botão **Ouvir** dessa tela reproduz a prévia antes de
salvar.

O modal e o bloco do canal vivem no layout do painel
(`layouts/partials/realtime-alerts.blade.php` + `layouts/partials/request-modal.blade.php`),
portanto aparecem em qualquer tela do atendente: painel, histórico, carteira,
detalhe da consulta e tela de atendimento. A fila em `dashboard/attendant.blade.php`
continua existindo como lista persistente e como fallback sem JavaScript.

Detalhes do alerta sonoro:

- os toques predefinidos são sintetizados com Web Audio API, sem arquivo de
  áudio no bundle; a sirene padrão varre 640–1180 Hz com filtro passa-baixa em
  2,2 kHz;
- o MP3 personalizado é baixado uma vez, decodificado no mesmo `AudioContext` e
  reproduzido pelo mesmo ciclo de repetição; se o arquivo sumir do disco, o
  servidor envia a sirene como fallback;
- navegadores só liberam áudio depois de uma interação: o `AudioContext` é
  criado no primeiro clique/tecla e o som, se já estiver agendado, começa a
  tocar nesse momento;
- o som para ao responder (aceitar ou recusar), ao fechar o modal pelo “X” e ao
  esvaziar a fila;
- um pedido que já estava aguardando quando a página abriu também abre o modal,
  porque a ressincronização inicial usa o mesmo caminho do evento.

O navegador nunca decide: aceitar/recusar chamam
`PATCH /consultas/{id}/aceitar` e `PATCH /consultas/{id}/recusar`
(`attendant.consultations.accept|reject`), que revalidam estado,
participação e disponibilidade. `403`/`409`/`410` significam que outra aba,
outro atendente ou o cliente chegaram primeiro — o pedido sai da fila e a lista
é ressincronizada; `422` mantém o modal aberto com a mensagem do servidor (por
exemplo, atendente já em outra consulta ativa).

### 9.5 Preferências de conversa e aviso de nova mensagem

Cada usuário escolhe como a conversa se comporta. As preferências ficam no perfil
(`users.chat_send_on_enter` e `users.chat_sound_enabled`, ambos `true` por padrão)
e valem para todas as consultas:

| Campo | Efeito |
|---|---|
| `chat_send_on_enter` | `true`: **Enter** envia e **Shift+Enter** quebra linha. `false`: Enter quebra linha e **Ctrl/Cmd+Enter** envia |
| `chat_sound_enabled` | liga/desliga o aviso sonoro de mensagem recebida |

Como funciona:

- o bloco `#chat` publica `data-send-on-enter`, `data-sound-enabled` e
  `data-preferences-url`; os interruptores usam `data-chat-preference`;
- ao mudar um interruptor, `resources/js/chat.js` atualiza o estado em memória, os
  `data-*`, o texto de ajuda (`[data-chat-send-mode]`) e envia **os dois campos**
  por `PATCH /preferencias/chat` com `Accept: application/json`. A falha de rede é
  silenciosa: o interruptor já mudou e a próxima alteração tenta novamente, sem
  bloquear a conversa;
- `ChatPreferenceController` valida `send_on_enter` e `sound_enabled` como booleanos
  obrigatórios e responde JSON (ou `back()` sem JavaScript);
- `Ctrl/Cmd+Enter` envia sempre, independentemente da preferência — é a saída para
  quem desliga o envio com Enter.

O aviso sonoro de nova mensagem também é sintetizado no navegador (oscilador seno
880→660 Hz, 0,46 s), sem arquivo de áudio no bundle:

- toca **apenas** para mensagens de outro participante: `notifyIncoming()` compara
  `sender_id` com `data-current-user-id`;
- no canal em tempo real (`consultation.message`) toca uma vez por mensagem recebida;
  no backfill HTTP (`after_id`) toca uma vez por lote, nunca uma vez por mensagem;
- fica em silêncio no carregamento inicial: o histórico vem renderizado pelo servidor
  e uma sincronização com a linha do tempo vazia é tratada como histórico;
- respeita `data-sound-enabled` e só toca depois de uma interação do usuário
  (`pointerdown`/`keydown`), porque navegadores bloqueiam áudio em `AudioContext`
  suspenso;
- abas fechadas ou sem JavaScript não tocam nada — o aviso sonoro depende do canal e
  do próprio chat abertos.

---

## 10. Diagnóstico: chat só atualiza pela busca periódica

Sintoma: com `queue` e `reverb` de pé, a mensagem só aparece depois do ciclo de
`GET /consultas/{id}/mensagens?after_id=…` (30 s) ou do
`POST /consultas/{id}/mensagens/lidas`, e o selo do chat fica em
“Sem conexão”/“Reconectando…”.

Ordem de verificação:

1. **O upgrade WebSocket acontece?** Em DevTools → Network → WS deve existir
   `ws://localhost:8080/app/<REVERB_APP_KEY>` com status `101`.
2. **`4009 Origin not allowed`** logo após `pusher:connection_established`
   significa origem não autorizada. O Reverb compara só o host (§2.1): ajuste
   `REVERB_ALLOWED_ORIGINS` e **reinicie o processo**.
3. **`/broadcasting/auth`**: `403` = usuário fora do canal (não participante ou
   conta inativa); `419` = CSRF ausente — o Echo envia o token de
   `<meta name="csrf-token">`, presente em todos os layouts do painel.
4. **O evento é despachado?** `docker compose logs -f queue` deve mostrar
   `App\Events\ConsultationMessageSent … DONE`. `php artisan queue:failed` lista
   erros de entrega do servidor ao Reverb.
5. **O Reverb está acessível com o `app_id` certo?** De dentro do container,
   `curl -o /dev/null -w '%{http_code}' http://reverb:8080/apps/<REVERB_APP_ID>/channels`
   deve responder `401` (endpoint existe, exige assinatura); `404` indica
   `REVERB_APP_ID` divergente entre servidor e cliente.

Teste de transporte sem navegador (Node 22+, sem dependências): abra um
WebSocket cru em `ws://localhost:8080/app/<REVERB_APP_KEY>?protocol=7&client=js&version=8.6.0`
enviando o cabeçalho `Origin: http://localhost:8000` e assine um canal público
de teste (`diagnostics`). Publique com uma requisição assinada para
`POST http://reverb:8080/apps/<REVERB_APP_ID>/events`. O esperado é
`101` → `pusher:connection_established` → `pusher_internal:subscription_succeeded`
→ frame do evento. Se aparecer `pusher:error 4009`, o transporte está saudável e
o problema é exclusivamente a lista de origens.



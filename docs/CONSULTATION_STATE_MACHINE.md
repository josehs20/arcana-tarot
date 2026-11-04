# Arcana Tarot — Máquina de Estados da Consulta

> Documento das etapas **Prompt 10 — Ciclo de vida**, **Prompt 11 — Chat
> persistente**, **Prompt 12 — Reverb/eventos em tempo real** e **Prompt 15 —
> Cobrança por tempo**.
> Fonte de verdade para estados, transições, autorização, presença, chat,
> cobrança e rotinas automáticas. Regras financeiras detalhadas estão em
> `docs/TIME_BILLING.md`; qualquer implementação futura (heartbeat) deve
> respeitar estas regras.

---

## 1. Estados

`consultations.status` usa o enum PHP `App\Enums\ConsultationStatus`
(string-backed, coluna `varchar(20)`).

| Estado | Valor | Aberto? | Significado |
|---|---|---|---|
| Solicitada | `requested` | Sim | Cliente pediu a consulta e aguarda decisão do atendente. |
| Em andamento | `active` | Sim | Atendente aceitou; chat e cartas liberados; cobrança pode ocorrer. |
| Concluída | `ended` | Não | Encerrada por cliente, atendente ou sistema. |
| Cancelada | `cancelled` | Não | Cliente desistiu antes do aceite. |
| Recusada | `rejected` | Não | Atendente recusou a solicitação. |
| Expirada | `expired` | Não | Solicitação passou do prazo sem decisão. |
| Interrompida | `interrupted` | Não | Sessão ativa encerrada por inatividade, desconexão ou falha técnica. |

Apenas `requested` e `active` são **abertos** (`isOpen()`), e `active` é o único
estado que permite mensagens (`allowsMessaging()`). Os demais são **finais** e
nunca reabrem.

`consultations.ended_reason` usa `App\Enums\ConsultationEndReason` e registra o
motivo operacional: `client_ended`, `attendant_ended`, `rejected`, `cancelled`,
`expired`, `out_of_credits`, `timeout`, `disconnected`, `inactivity`,
`client_logged_out`, `attendant_logged_out`, `call_ended`, `call_failed` e
`other`. O motivo é um rótulo; nunca contém texto íntimo ou trecho de conversa.

---

## 2. Diagrama

```
                 aceite do atendente
requested ───────────────────────────────► active
    │                                         │
    │ recusa do atendente                     │ encerramento (cliente/atendente/sistema)
    ├──────────────────────────────► rejected │
    │                                         ├──────────────────────────► ended
    │ cancelamento do cliente                 │
    ├────────────────────────────► cancelled  │ desconexão / tempo limite
    │                                         ├──────────────────► interrupted
    │ expiração (TTL)                         │
    └───────────────────────────────► expired └──────────────────► ended
                                                                  (out_of_credits/timeout)
```

Não existe transição de estado final para aberto. Repetir uma operação já
concluída é **idempotente** (retorna a consulta sem duplicar auditoria).

---

## 3. Transições

| Ação | Ator | De | Para | `ended_reason` | Regras |
|---|---|---|---|---|---|
| Solicitar | Cliente ativo | — | `requested` | — | Atendente ativo, publicado, `online` e sem consulta ativa; cliente sem consulta aberta; preço configurado. |
| Aceitar | Atendente escalado | `requested` | `active` | — | Atendente ativo/publicado/online; cliente ativo; ambos sem outra consulta aberta/ativa; saldo ≥ mínimo; grava snapshots de comissão e tarifa; **cobra o primeiro minuto**. |
| Aceitar novamente | Atendente escalado | `active` | `active` | — | No-op com mensagem de já estar em andamento. |
| Recusar | Atendente escalado | `requested` | `rejected` | `rejected` | — |
| Recusar novamente | Atendente escalado | `rejected` | `rejected` | `rejected` | No-op. |
| Cancelar | Cliente dono | `requested` | `cancelled` | `cancelled` | Consulta ativa **não** pode ser cancelada; deve ser encerrada. |
| Cancelar novamente | Cliente dono | `cancelled` | `cancelled` | `cancelled` | No-op. |
| Encerrar | Cliente dono | `active` | `ended` | `client_ended` | Liquida o último minuto iniciado; encerramento repetido em estado final é no-op. |
| Encerrar | Atendente escalado | `active` | `ended` | `attendant_ended` | Liquida o último minuto iniciado; no-op em estado final. |
| Encerrar pelo sistema | Sistema | `active` | `ended` | `out_of_credits`, `timeout`, etc. | Usado pela cobrança quando o saldo acaba; liquida os minutos devidos e não cria débito parcial. |
| Encerrar no logout | Sistema | `active` | `ended` | `client_logged_out` ou `attendant_logged_out` | Idempotente; liquida os minutos vencidos e libera a presença do atendente. |
| Expirar | Sistema | `requested` | `expired` | `expired` | Apenas se `created_at` passou do TTL. |
| Interromper | Sistema | `active` | `interrupted` | `inactivity` (rotina) ou `disconnected` (falha técnica) | Liquida os minutos vencidos até `ended_at`. |

Toda transição executa em transação com `SELECT ... FOR UPDATE` na consulta.
As validações retornam `ValidationException` (erro de formulário) ou
`AuthorizationException` (ator sem permissão).

---

## 4. Invariantes e concorrência

1. **Um cliente, uma consulta aberta** — nenhum cliente pode ter mais de uma
   consulta `requested`/`active`. Garantido no model e pelos índices únicos
   `open_client_id` (preenchido apenas em estados abertos, `NULL` nos finais).
2. **Um atendente, uma consulta ativa** — garantido no model e pelo índice
   único `active_attendant_id` (preenchido apenas em `active`).
3. **Participantes imutáveis** — `client_id` e `attendant_id` não podem ser
   alterados depois da criação.
4. **Estados finais são terminais** — `open_client_id`/`active_attendant_id`
   voltam a `NULL`, liberando as travas para uma próxima consulta.
5. **Snapshots no aceite** — `commission_rate_bps`,
   `rate_credits_per_minute` e `credit_unit_price_cents` são copiados do perfil
   e da configuração vigente. Alterações posteriores não afetam a consulta.
6. **Toda transição é auditada** — `audit_logs` recebe `action`, `actor_id`,
   sujeito e metadados `from`/`to`/`reason` (sem conteúdo de conversa).
7. **Cobrança é server-side e idempotente** — `billed_minutes` é o cursor de
   minutos lançados, atualizado na mesma transação do débito; a chave
   `consultation:{id}:minute:{n}` impede duplicidade. Nenhum débito parcial ou
   saldo negativo é permitido; detalhes em `docs/TIME_BILLING.md`.
8. **Comissão após a liquidação** — em `ended` ou `interrupted`, depois de
   liquidar a cobrança, o servidor gera um único `accrual` sobre os débitos
   efetivos da consulta usando o snapshot da taxa. Recusada/cancelada/expirada
   sem início ou sem cobrança positiva não gera comissão; detalhes em
   `docs/COMMISSION_POLICY.md`.

As colunas de trava são calculadas pelo model `Consultation` no evento
`saving`; o banco é a última barreira contra duas requisições simultâneas.

---

## 5. Autorização

`App\Policies\ConsultationPolicy` concentra as regras HTTP; o
`ConsultationLifecycleManager` repete as checagens de domínio.

| Habilidade | Quem pode |
|---|---|
| `viewAny` | Admin ativo (listagem administrativa). |
| `request`/`create` | Cliente ativo. |
| `view` | Admin ativo, cliente dono ou atendente escalado. |
| `accept` | Atendente escalado, ativo, em `requested` (ou `active` para no-op). |
| `reject` | Atendente escalado, ativo, em `requested` (ou `rejected` para no-op). |
| `cancel` | Cliente dono, ativo, em `requested` (ou `cancelled` para no-op). |
| `end` | Participante ativo, em `active` ou estado final (no-op). |
| `viewMessages` | Cliente dono ou atendente escalado, ativo; histórico disponível em consulta ativa ou final. Admin **não** lê a conversa. |
| `sendMessage` | Participante ativo em consulta `active`. |
| `sendCard` | Atendente escalado em consulta `active`. |

Rotas de transição também aplicam `role:client`, `role:attendant` ou
`role:admin` conforme o ator. Um cliente não acessa endpoints de aceite/recusa,
e um atendente não acessa o cancelamento do cliente.

---

## 6. Presença do atendente

- `attendant_profiles.availability` persiste somente `online`/`offline`;
  `busy` é sempre derivado de consulta `active` e nunca gravado.
- `attendant_profiles.last_seen_at` é atualizado por `markSeen()` quando o
  atendente fica online, abre o painel, visualiza a consulta ou aceita a
  solicitação. A escrita é limitada por `last_seen_touch_seconds` para evitar
  update em cada requisição.
- Ao encerrar/interromper, `releaseAttendantPresence()` mantém o atendente
  `online` somente se houver presença recente
  (`presence_timeout_minutes`); caso contrário, volta para `offline` e registra
  auditoria `attendant.availability_changed` com motivo
  `stale_presence_after_consultation`.
- No **logout** de um usuário com papel `attendant`,
  `AttendantAvailabilityManager::signOut()` coloca o perfil em `offline`
  (auditoria `attendant.availability_changed` com motivo `logout`). O logout
  nunca falha por causa disso: sem perfil, já `offline` ou com consulta `active`
  nada muda — a disponibilidade continua congelada para que a rotina de
  presença possa interromper a sessão órfã.
- A presença do **navegador** é registrada em `consultation_presence_sessions`
  por batimentos HTTP. Cada aba tem um token opaco; o estado exibido é
  `online`, `reconnecting` ou `offline` por papel. Batimento não conta como
  atividade: uma aba aberta sem interação também é interrompida. O logout
  encerra a consulta ativa com motivo `client_logged_out` ou
  `attendant_logged_out`. Regras completas em
  `docs/SEGURANCA_E_USABILIDADE_CHAT.md`.

---

## 7. Rotinas automáticas

Agendadas em `routes/console.php` com `everyMinute()` e
`withoutOverlapping()`:

| Comando | Efeito |
|---|---|
| `consultations:expire-requests` | Expira `requested` cujo `created_at` passou de `CONSULTATION_REQUEST_TTL_MINUTES` (padrão 10). |
| `consultations:interrupt-disconnected` | Interrompe `active` sem nenhuma interação por `consultation_inactivity_timeout_minutes` + `consultation_reconnection_tolerance_seconds`; liquida os minutos vencidos e audita motivo `inactivity`. O nome do comando é histórico: a decisão usa atividade, não presença. |
| `consultations:charge-minutes` | Cobra os minutos iniciados ainda não lançados de consultas `active`; encerra como `out_of_credits` quando não há saldo para o próximo minuto. |

Todos são idempotentes e reportam no console quantas consultas mudaram de
estado ou quantos minutos foram cobrados.


---

## 8. Mensagens e cartas

`ConsultationMessage` valida no evento `saving()`:

- a consulta existe;
- está `active` (não basta existir);
- o remetente é o cliente ou o atendente escalado;
- `type = tarot_card` só é aceito do atendente.

Assim, jobs, comandos e importações não conseguem gravar mensagens em consulta
recusada/expirada nem falsificar cartas. A policy HTTP repete as mesmas regras
para devolver 403 na borda.

### 8.1 Chat persistente (HTTP)

- `POST /consultas/{id}/mensagens` recebe apenas `body` (texto de até 2.000
  caracteres, configurável). Remetente e `created_at` vêm do servidor.
- `POST /consultas/{id}/cartas` recebe apenas `tarot_card_id`. O atendente
  escalado escolhe uma carta ativa de baralho ativo; o servidor copia a imagem
  para `tarot/consultation-cards/{consultation_id}/{aleatório}.{ext}` e grava
  `deck_name`, `card_name` e o caminho do snapshot. Metadados e caminhos do
  navegador são ignorados.
- `GET /consultas/{id}/mensagens/{message}/imagem` serve somente o snapshot
  daquela mensagem, com `Cache-Control: private`, para participantes. Não
  aceita caminhos arbitrários e não usa o caminho atual do catálogo.
- O histórico é paginado (25 por página), consultado do mais recente para o
  mais antigo e exibido em ordem cronológica. Texto sempre escapado.
- Apenas consulta `active` aceita escrita; estados finais exibem o histórico
  em modo somente leitura.
- `viewMessages` nega acesso ao admin e a terceiros. O admin continua vendo os
  metadados da consulta, mas não a conversa.
- Limites de frequência são aplicados por participante + consulta:
  30 mensagens/min e 15 cartas/min por padrão.
- `client_last_read_at`/`attendant_last_read_at` são atualizados ao abrir a
  conversa e após mensagens recebidas em tempo real; os painéis exibem a
  contagem de mensagens recebidas desde a última leitura.

### 8.2 Tempo real e fallback (Prompt 12)

- O canal privado `consultation.{id}` é autorizado no servidor somente para
  cliente/atendente ativos da consulta. Admin, terceiros e usuários inativos
  recebem 403.
- `ConsultationMessageSent` transmite texto/carta após o commit; o payload de
  carta contém apenas dados públicos e `image_url` da rota autorizada, nunca o
  caminho privado.
- `ConsultationStateChanged` transmite mudanças reais de estado após o commit;
  aceite/encerramento repetidos são no-ops e não emitem evento.
- O navegador deduplica por ID, busca lacunas em
  `GET /consultas/{id}/mensagens?after_id={último_id}` após reconexão/retorno à
  aba e a cada 30 segundos. O estado retornado corrige eventos perdidos.
- O WebSocket é apenas transporte: a persistência e a autorização HTTP
  continuam sendo a fonte de verdade. Detalhes em `docs/REALTIME.md`.

---

## 9. Configuração

`config/consultation.php`:

| Chave | Env | Padrão | Uso |
|---|---|---|---|
| `minimum_balance_minutes` | `CONSULTATION_MINIMUM_BALANCE_MINUTES` | 1 | Saldo mínimo (em minutos) para aceitar. |
| `request_ttl_minutes` | `CONSULTATION_REQUEST_TTL_MINUTES` | 10 | TTL de solicitações pendentes. |
| `presence_timeout_minutes` | `CONSULTATION_PRESENCE_TIMEOUT_MINUTES` | 15 | Janela para liberar a disponibilidade do atendente; não interrompe a consulta. |
| `last_seen_touch_seconds` | `CONSULTATION_LAST_SEEN_TOUCH_SECONDS` | 30 | Intervalo mínimo entre gravações de presença. |
| `inactivity_timeout_minutes` | `settings` (admin), fallback `CONSULTATION_INACTIVITY_TIMEOUT_MINUTES` | 5 | Tempo sem interação antes da interrupção automática. |
| `reconnection_tolerance_seconds` | `settings` (admin), fallback `CONSULTATION_RECONNECTION_TOLERANCE_SECONDS` | 90 | Margem extra para quedas breves de rede. |
| `activity_ping_seconds` | `CONSULTATION_ACTIVITY_PING_SECONDS` | 20 | Intervalo do batimento de presença do navegador. |
| `navigation_warning_seconds` | `CONSULTATION_NAVIGATION_WARNING_SECONDS` | 20 | Aviso local após tempo sem interação. |
| `message_max_length` | `CONSULTATION_MESSAGE_MAX_LENGTH` | 2000 | Tamanho máximo do texto de uma mensagem. |
| `message_page_size` | `CONSULTATION_MESSAGE_PAGE_SIZE` | 25 | Mensagens por página do histórico. |
| `message_rate_per_minute` | `CONSULTATION_MESSAGE_RATE_PER_MINUTE` | 30 | Limite de mensagens por participante/consulta. |
| `card_rate_per_minute` | `CONSULTATION_CARD_RATE_PER_MINUTE` | 15 | Limite de cartas por participante/consulta. |

O saldo mínimo em centavos é derivado de
`rate_credits_per_minute × credit_unit_price_cents × minimum_balance_minutes`
por `ConsultationPricing`. Nenhum valor de negócio fica fixo no código.

`rate_credits_per_minute`, `credit_unit_price_cents` e
`minimum_balance_minutes` vivem em `settings` e são editáveis em
`/admin/financeiro` (admin), com auditoria `consultation_pricing.updated`. O
aceite copia os dois primeiros para a consulta; mudanças posteriores não
alteram sessões em andamento.

O contador visual é carregado por `resources/js/consultation-timer.js` a partir
de `started_at`/`ended_at`; ele não calcula saldo e não participa da cobrança.

---

## 10. Auditoria

Ações registradas em `audit_logs` (metadados sem conteúdo sensível):

- `consultation.requested` — cliente, atendente e status.
- `consultation.accepted` — preço por minuto, saldo mínimo, comissão snapshot e
  primeiro minuto cobrado.
- `consultation.rejected` — motivo.
- `consultation.cancelled` — motivo.
- `consultation.ended` — motivo, duração em segundos e minutos/centavos
  liquidados.
- `consultation.expired` — motivo.
- `consultation.interrupted` — motivo e minutos/centavos liquidados.
- `consultation_safety.updated` — limites anteriores e novos de inatividade e
  reconexão.
- `consultation_pricing.updated` — valores anteriores e novos da tarifa.
- `attendant.availability_changed` — mudança automática de presença.

---

## 11. Cobertura de testes

`tests/Feature/ConsultationLifecycleTest.php` cobre:

- solicitação válida, bloqueio de duplicidade aberta e atendente offline/não
  publicado;
- aceite, snapshots, idempotência e saldo mínimo;
- recusa e cancelamento idempotentes;
- matriz de autorização (participantes, terceiros e admin);
- encerramento pelo cliente e liberação de presença;
- expiração e interrupção automáticas com relógio controlado;
- bloqueio de mensagens fora de consulta ativa e de cartas enviadas pelo
  cliente.

`tests/Feature/ConsultationChatTest.php` cobre o chat persistente:

- envio textual, identificação de remetente e ordem cronológica;
- paginação e limite máximo de texto;
- envio de carta pelo atendente escalado, snapshot privado e histórico após
  inativação/substituição do catálogo;
- rejeição de carta/baralho inativos, imagem ausente e remetente não atendente;
- matriz de acesso a histórico e imagens (participantes, terceiros e admin);
- consulta encerrada em modo somente leitura;
- escape de HTML e indicador de mensagens novas;
- limite de frequência por participante/consulta.

`tests/Feature/ConsultationRealtimeTest.php` cobre autorização do canal privado,
eventos textuais/de carta, URL autorizada da imagem, recuperação por `after_id`,
leitura em tempo real e emissão de estado somente após mudança commitada.

`tests/Feature/ConsultationBillingTest.php` cobre primeiro minuto no aceite,
avanço idempotente por minuto, encerramento automático por saldo, liquidação no
encerramento/interrupção, ausência de cobrança após estado final e snapshot de
tarifa. `tests/Feature/AdminFinanceTest.php` cobre a edição da tarifa e sua
auditoria.

`tests/Feature/ConsultationSafetyTest.php` cobre batimento e sessões de
presença, saída sem encerramento, logout idempotente, interrupção por
inatividade com tolerância de reconexão, limites administrativos auditados e
retorno da compra de créditos para o atendimento ativo.

---

## 12. Fora do escopo desta etapa

- Tempo máximo de sessão além do limite de inatividade. A interrupção usa
  inatividade + tolerância de reconexão (`docs/SEGURANCA_E_USABILIDADE_CHAT.md`).
- Presença por WebSocket; o batimento de presença continua em HTTP.
- Encerramento automático quando apenas um dos participantes fica inativo
  enquanto o outro continua interagindo.
- Anexos livres, áudio, vídeo e URLs arbitrárias.
- Cálculo e lançamento de comissão (Prompt 16).
- Estorno/cancelamento financeiro de consultas já iniciadas.


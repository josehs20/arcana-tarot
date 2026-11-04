# Arcana Tarot — Modelo de Dados

> Documento produzido na etapa **Prompt 03 — Modelagem inicial do banco**.
> Fonte de verdade do esquema até que migrations futuras o alterem.

Convenções gerais:

- Dinheiro é armazenado em **centavos inteiros** (`unsignedBigInteger`); nunca `float`.
- Estados são `string` + enum PHP (`app/Enums`), não enums nativos do banco (portabilidade).
- Lançamentos (crédito e comissão) são **imutáveis** (`created_at` apenas, sem `updated_at`).
- Arquivos (imagens de cartas) ficam em **storage privado**; o MySQL guarda apenas caminhos/metadata.

---

## 1. Diagrama textual de entidades e relações

```
users (1) ──── (1) wallets
users (1) ──── (1) attendant_profiles
users (1) ──── (*) payments
payments (1) ──── (*) payment_webhook_events
users (1) ──── (*) consultations            [client_id]
users (1) ──── (*) consultations            [attendant_id]
users (1) ──── (*) consultation_messages    [sender_id]
users (1) ──── (*) commission_ledger_entries [attendant_id]
users (0..1) ── (*) attendant_applications  [reviewed_by]
users (0..1) ── (*) audit_logs              [actor_id]

wallets (1) ──── (*) credit_ledger_entries  [wallet_id]
consultations (1) ──── (*) consultation_messages
consultations (1) ──── (*) commission_ledger_entries
tarot_decks (1) ──── (*) tarot_cards        [deck_id]
tarot_cards (0..1) ── (*) consultation_messages [tarot_card_id, nullable]

polimórficas:
  credit_ledger_entries.reference   -> pagamento/consulta/etc.
  audit_logs.subject                -> entidade auditada
```

---

## 2. Entidades e colunas

### 2.1 `users` (existente + papéis)

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `name` | string | |
| `email` | string unique | |
| `email_verified_at` | timestamp nullable | |
| `password` | string | |
| `remember_token` | string nullable | |
| `role` | string(20) | enum `UserRole`: `admin`, `attendant`, `client` |
| `is_active` | boolean | desativação administrativa |
| `phone` | string(20) nullable | índice; obrigatório na validação do cadastro |
| `phone_verified_at` | timestamp nullable | reservado para verificação por SMS |
| `theme_preference` | string(16) | enum `ThemePreference`; padrão `light` |
| `chat_send_on_enter` | boolean | padrão `true`; Enter envia e Shift+Enter quebra linha |
| `chat_sound_enabled` | boolean | padrão `true`; aviso sonoro de mensagem recebida |
| `timestamps` | | |

Índices: `email` (unique), `role`, `phone`.

### 2.2 `attendant_profiles` — perfil profissional

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK users (unique) | 1:1; cascadeOnDelete |
| `display_name` | string | nome público |
| `bio` | text nullable | apresentação |
| `photo_path` | string nullable | foto pública opcional no disco `public` (`storage/app/public`) |
| `specialties` | json nullable | lista de especialidades |
| `request_sound` | string(20) | toque de nova solicitação no painel; enum `AttendantRequestSound`, padrão `siren` |
| `request_sound_path` | string nullable | MP3 personalizado no disco `public` (`attendant-sounds/`), usado quando `request_sound = custom` |
| `commission_rate_bps` | unsignedInteger | comissão individual em **basis points** (0–10000) |
| `is_published` | boolean | status de publicação |
| `availability` | string(20) | `offline`/`online`; `busy` é derivado de consulta ativa |
| `last_seen_at` | timestamp nullable | sinal de presença para heartbeat/encerramento (Prompt 10) |
| `timestamps` | | |

> `photo_path` e `availability` foram adicionados no Prompt 06 para o site
> público. O toggle manual online/offline no painel e as transições de estado são
> implementados no Prompt 08; `busy` nunca é persistido. `last_seen_at` foi
> adicionado no Prompt 10 e sustenta a política de presença descrita em
> `docs/CONSULTATION_STATE_MACHINE.md`. `request_sound` aceita `siren`
> (padrão), `bell`, `chime`, `pulse` e `custom`; os quatro primeiros são
> sintetizados no navegador e `custom` usa o MP3 apontado por
> `request_sound_path` (até 5 segundos e 1 MB, no disco `public`). Se o arquivo
> personalizado faltar, o painel volta a tocar a sirene. O som só altera o aviso
> de nova solicitação, nunca o aceite ou a cobrança.

### 2.2.1 `attendant_modality_prices` — preço por atendente e modalidade

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `attendant_profile_id` | FK attendant_profiles | cascadeOnDelete |
| `modality` | string(20) | enum `ConsultationModality` (`text`, `audio`, `video`) |
| `price_per_minute_cents` | unsignedInteger | valor cobrado por minuto iniciado |
| `timestamps` | | |

Índice único `(attendant_profile_id, modality)`. É a **única** fonte do preço de
novas consultas: o aceite copia o valor para
`consultations.price_per_minute_cents`. Modalidade sem linha (ou com valor
zero) significa “preço não configurado” e bloqueia o pedido/aceite — não existe
tarifa global de fallback. A edição acontece em **Admin → Modalidades** e é
auditada como `attendant.modalities_updated`.

### 2.3 `consultations` — consultas/sessões

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `client_id` | FK users | restrictOnDelete |
| `attendant_id` | FK users | restrictOnDelete |
| `status` | string(20) | enum `ConsultationStatus`: `requested`, `active`, `ended`, `cancelled`, `rejected`, `expired`, `interrupted` |
| `open_client_id` | unsignedBigInteger nullable | trava única: preenchido em `requested`/`active`, `NULL` em estados finais |
| `active_attendant_id` | unsignedBigInteger nullable | trava única: preenchido apenas em `active` |
| `commission_rate_bps` | unsignedInteger nullable | **snapshot** da comissão no início oficial |
| `price_per_minute_cents` | unsignedInteger nullable | **snapshot** do preço do atendente para a modalidade no aceite |
| `rate_credits_per_minute` | unsignedInteger nullable | coluna **legada** (espelho em créditos do preço congelado; nula em consultas novas quando o crédito não divide o preço) |
| `credit_unit_price_cents` | unsignedBigInteger nullable | **snapshot** do preço do crédito no aceite (legado, mantido para auditoria) |
| `started_at` / `ended_at` | timestamp nullable | controlados pelo servidor (aceite/encerramento) |
| `last_activity_at` | timestamp nullable | relógio agregado de interação; base da interrupção por inatividade |
| `client_last_activity_at` / `attendant_last_activity_at` | timestamp nullable | última interação por papel; diagnóstico, não decide a interrupção |
| `ended_reason` | string(40) nullable | enum `ConsultationEndReason`; inclui `inactivity`, `client_logged_out` e `attendant_logged_out` |
| `client_last_read_at` / `attendant_last_read_at` | timestamp nullable | última leitura do chat por participante; controlado pelo servidor (não mass-assignable) |
| `timestamps` | | |

Índices: `(attendant_id, status)`, `(client_id, created_at)`, `status`,
`(status, last_activity_at)`, e únicos em `open_client_id` e
`active_attendant_id`.

> As colunas de trava são calculadas pelo model `Consultation` no evento
> `saving` e funcionam como última barreira contra concorrência: no máximo uma
> consulta aberta por cliente e uma ativa por atendente. Os índices únicos
> ignoram `NULL`, preservando todo o histórico.

### 2.4 `consultation_messages` — mensagens do chat

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `consultation_id` | FK consultations | cascadeOnDelete |
| `sender_id` | FK users | restrictOnDelete |
| `type` | string(20) | enum `MessageType`: `text`, `tarot_card` |
| `body` | text nullable | conteúdo textual; `NULL` em mensagens de carta |
| `tarot_card_id` | FK tarot_cards nullable | referência quando aplicável; nullOnDelete |
| `deck_name` | string nullable | **snapshot** nome do baralho |
| `card_name` | string nullable | **snapshot** nome da carta |
| `image_path` | string nullable | **snapshot** privado em `tarot/consultation-cards/{consultation_id}`; nunca o caminho do catálogo |
| `timestamps` | | |

Índice: `(consultation_id, created_at)`.

> Somente cliente e atendente participantes leem o histórico (inclusive após o
> encerramento). O administrador vê os metadados da consulta, mas não a conversa.
> O texto é renderizado escapado e o conteúdo nunca é gravado em logs de
> aplicação.

### 2.4.1 `consultation_presence_sessions` — presença por aba/dispositivo

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `consultation_id` | FK consultations | cascadeOnDelete |
| `user_id` | FK users | cascadeOnDelete |
| `role` | string(20) | `client` ou `attendant` |
| `session_token` | string(64) unique | token opaco gerado pelo servidor; guardado no `sessionStorage` |
| `connected_at` | timestamp | primeiro batimento daquela sessão |
| `last_seen_at` | timestamp | último batimento; alimenta o estado online/reconectando/offline |
| `disconnected_at` | timestamp nullable | saída, logout ou encerramento |
| `disconnect_reason` | string(40) nullable | ex.: `user_left`, `inactivity`, `client_logged_out` |
| `timestamps` | | |

Índices: `(consultation_id, role, last_seen_at)` e
`(consultation_id, user_id, disconnected_at)`. A presença não reinicia o relógio
de atividade; a política completa está em
`docs/SEGURANCA_E_USABILIDADE_CHAT.md`.

### 2.5 `tarot_decks` — baralhos

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `name` | string | |
| `slug` | string unique | |
| `description` | text nullable | |
| `is_active` | boolean | arquivar/inativar em vez de apagar |
| `timestamps` | | |

### 2.6 `tarot_cards` — cartas

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `deck_id` | FK tarot_decks | restrictOnDelete |
| `name` | string | |
| `slug` | string | unique `(deck_id, slug)` |
| `sort_order` | unsignedInteger | ordem de exibição |
| `meaning_upright` | text nullable | significado normal |
| `meaning_reversed` | text nullable | significado invertido |
| `image_path` | string nullable | caminho privado da imagem (nunca o binário) |
| `image_mime` | string(40) nullable | MIME real validado no servidor (`image/jpeg`, `image/png`, `image/webp`) |
| `image_size` | unsignedBigInteger nullable | tamanho em bytes |
| `image_checksum` | string(64) nullable | SHA-256 do arquivo no momento do upload |
| `is_active` | boolean | |
| `timestamps` | | |

### 2.7 `wallets` — carteira de créditos

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK users (unique) | 1:1; cascadeOnDelete |
| `balance_cents` | unsignedBigInteger | **cache** do livro-razão; nunca editável livremente |
| `timestamps` | | |

> A carteira é criada vazia no cadastro do cliente e pode ser criada sob
> demanda para contas antigas. O saldo é uma projeção: toda alteração deve
> ocorrer na mesma transação de um lançamento.

### 2.8 `credit_ledger_entries` — lançamentos de crédito/débito (imutável)

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `wallet_id` | FK wallets | restrictOnDelete |
| `type` | string(20) | enum `LedgerEntryType`: `credit`, `debit` |
| `amount_cents` | unsignedBigInteger | valor movimentado, sempre positivo |
| `balance_after_cents` | unsignedBigInteger | saldo resultante, nunca negativo |
| `reference_type` / `reference_id` | morphs nullable | origem server-side (pagamento, consulta, auditoria…) |
| `reason` | string(500) nullable | obrigatório em ajuste administrativo |
| `created_by` | FK users nullable | autor humano do lançamento; nullOnDelete |
| `idempotency_key` | string unique | garante idempotência global |
| `created_at` | timestamp | **sem** `updated_at` (imutável) |

Índices: `(wallet_id, created_at)` e `idempotency_key` (unique). O model
`CreditLedgerEntry` bloqueia `update` e `delete`; correções geram novo
lançamento. Ajustes administrativos referenciam o `audit_logs` correspondente.
Invariantes detalhadas em `docs/WALLET_LEDGER.md`.

### 2.9 `payments` — recargas/pagamentos

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK users | restrictOnDelete |
| `provider` | string(40) | ex.: `pix_sandbox` |
| `provider_payment_id` | string nullable unique | identificador devolvido pelo provedor |
| `external_reference` | string(80) nullable unique | referência gerada pelo servidor e enviada ao provedor |
| `amount_cents` | unsignedBigInteger | valor pago |
| `credits` | unsignedInteger | créditos concedidos (quantidade, não centavos) |
| `credit_unit_price_cents` | unsignedBigInteger | snapshot do preço na compra |
| `status` | string(20) | enum `PaymentStatus`: `pending`, `confirmed`, `expired`, `failed`, `refunded` |
| `raw_status` | string nullable | status normalizado do provedor |
| `pix_copy_paste` | text nullable | instrução do pagamento, criptografada pelo cast `encrypted` |
| `failure_reason` | string(120) nullable | motivo seguro da falha, sem payload/PII |
| `idempotency_key` | string unique | `pix_recharge:{user_id}:{operation_id}` |
| `expires_at` | timestamp nullable | expiração informada pelo provedor |
| `paid_at` | timestamp nullable | preenchido somente na confirmação server-side |
| `timestamps` | | |

Índice: `(user_id, status)`. A confirmação só ocorre via
`PaymentManager::handleWebhook()`; nenhum endpoint de navegador altera
`status` ou `paid_at`.

### 2.9.1 `payment_webhook_events` — eventos de pagamento recebidos

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `payment_id` | FK payments nullable | nullOnDelete; nulo quando o pagamento é desconhecido |
| `provider` | string(40) | provedor que originou o evento |
| `provider_event_id` | string(120) unique | **idempotência/replay** do webhook |
| `event_type` | string(60) | ex.: `payment.updated` |
| `provider_payment_id` | string(120) nullable | correlação com `payments.provider_payment_id` |
| `status` | string(20) | enum `PaymentEventStatus`: `processed`, `ignored`, `failed` |
| `failure_reason` | string(80) nullable | `unknown_payment`, `amount_mismatch` etc. |
| `payload_hash` | char(64) | SHA-256 do corpo; o payload bruto não é persistido |
| `amount_cents` | unsignedBigInteger nullable | valor declarado no evento |
| `occurred_at` | timestamp nullable | horário informado pelo provedor |
| `processed_at` | timestamp nullable | fim do processamento |
| `timestamps` | | |

Índices: `provider_event_id` (unique), `(provider_payment_id, created_at)` e
`(status, created_at)`. A unicidade impede crédito duplicado em reenvios; a
transação de processamento também usa `lockForUpdate` no pagamento. Detalhes em
`docs/PIX_SANDBOX.md`.

### 2.10 `attendant_applications` — candidaturas "Trabalhe conosco"

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `name` / `email` | string | dados de contato; e-mail normalizado em minúsculas |
| `phone` | string(30) nullable | form required; armazenado como DDD + número (somente dígitos) |
| `city` / `state` | string(120) / char(2) nullable | localização opcional; UF em maiúsculas |
| `experience_summary` | text nullable | experiência com Tarot (obrigatória no formulário) |
| `message` | text nullable | apresentação/abordagem de atendimento (obrigatória no formulário) |
| `specialties` | json nullable | até 8 áreas, normalizadas; `null` quando não informadas |
| `portfolio_url` | string(255) nullable | link http/https opcional |
| `status` | string(20) | enum `ApplicationStatus`: `pending`, `under_review`, `approved`, `rejected` |
| `reviewed_by` | FK users nullable | administrador que assumiu/decidiu; nullOnDelete |
| `review_notes` | text nullable | nota interna atual; nunca exposta publicamente |
| `submitted_at` | timestamp nullable | envio |
| `consent_at` | timestamp nullable | prova do consentimento para análise |
| `status_changed_at` | timestamp nullable | última transição de estado |
| `review_started_at` | timestamp nullable | início da análise (`under_review`) |
| `reviewed_at` | timestamp nullable | data da decisão final |
| `timestamps` | | `updated_at` cobre alterações de nota/revisor |

Índices: `(email)`, `(status)`, `(email, status)`, `(status, submitted_at)`.
**Aprovação não concede acesso automaticamente.**

Política de duplicidade (D-019):

- Enquanto houver candidatura `pending` ou `under_review` com o mesmo e-mail
  normalizado, uma nova submissão é recusada com mensagem explícita ao
  candidato.
- Candidatura `approved` bloqueia novas submissões para o mesmo e-mail; ajustes
  de acesso são tratados pela equipe.
- Após `rejected`, é permitida uma nova candidatura somente após **30 dias** da
  decisão (`reviewed_at`; fallback `updated_at`). O prazo é informado na
  mensagem de erro.
- A submissão não cria `users`, papel `attendant`, senha ou credenciais.

Auditoria: cada transição grava `audit_logs.action =
attendant_application.under_review|approved|rejected`, `actor_id` e
`metadata` apenas com `from`/`to`. O conteúdo da candidatura e as notas não são
copiados para a auditoria.

### 2.11 `commission_ledger_entries` — comissão por consulta (imutável)

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `attendant_id` | FK users | restrictOnDelete |
| `consultation_id` | FK consultations nullable | nullable p/ ajustes legados; accrual/estorno sempre vinculados |
| `reversed_entry_id` | FK commission_ledger_entries nullable unique | aponta o accrual estornado; impede estorno duplicado |
| `commission_rate_bps` | unsignedInteger | percentual aplicado (snapshot) |
| `base_amount_cents` | unsignedBigInteger | base monetária efetivamente cobrada |
| `commission_amount_cents` | unsignedBigInteger | valor calculado; sempre positivo |
| `type` | string(20) | enum `CommissionEntryType`: `accrual`, `reversal`, `adjustment` |
| `actor_id` | FK users nullable | administrador responsável por estorno/ajuste; nulo no accrual automático |
| `reason` | text nullable | motivo de estorno/ajuste |
| `idempotency_key` | string unique | `consultation:{id}:commission:accrual` ou `...:reversal:{accrual_id}` |
| `created_at` | timestamp | **sem** `updated_at` (imutável) |

Índices: `(attendant_id, created_at)`, `consultation_id`, único em
`reversed_entry_id`.

> Accruals e adjustments somam; reversals subtraem do total. O valor do estorno
> é persistido positivo e o sinal é derivado do `type`. O model bloqueia
> `update` e `delete`; correções sempre geram um novo lançamento auditado.
> Regras completas em `docs/COMMISSION_POLICY.md`.

### 2.12 `audit_logs` — auditoria administrativa

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `actor_id` | FK users nullable | nullOnDelete |
| `action` | string(100) | ex.: `catalog.updated` |
| `subject_type` / `subject_id` | morphs nullable | entidade auditada |
| `metadata` | json nullable | sem PII/segredos |
| `created_at` | timestamp | **sem** `updated_at` |

### 2.13 `settings` — parâmetros configuráveis

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `key` | string unique | ex.: `credit_unit_price_cents` |
| `value` | text nullable | |
| `type` | string(20) | p/ casting (`string`, `integer`, `boolean`) |
| `timestamps` | | |

---

## 3. Invariantes do domínio

1. **Dinheiro em centavos inteiros** — `amount_cents`, `balance_cents`, `base_amount_cents`, `commission_amount_cents`, `credit_unit_price_cents` são inteiros; jamais `float`/`double`.
2. **Saldo é derivado do livro-razão** — `wallets.balance_cents` é cache atualizado na mesma transação do lançamento; nunca é editado sem um `credit_ledger_entries` correspondente.
3. **Lançamentos imutáveis** — `credit_ledger_entries` e `commission_ledger_entries` não têm `updated_at`; correções/estornos são **novos lançamentos** (`reversal`/`adjustment`).
4. **Idempotência** — `idempotency_key` único em lançamentos e pagamentos, `provider_event_id` único em webhooks e a chave determinística `payment:{id}` no ledger evitam duplicidade em reenvios/reprocessamento.
5. **Snapshot de comissão** — `consultations.commission_rate_bps` é copiado de `attendant_profiles.commission_rate_bps` no início oficial da sessão; alterações futuras na taxa não afetam consultas passadas.
6. **Comissão sobre o valor cobrado** — `base_amount_cents` é o valor monetário efetivamente debitado da consulta no razão de créditos; o cálculo ocorre no servidor com aritmética inteira e arredondamento half-up, gravando `commission_amount_cents` imutável. A chave `consultation:{id}:commission:accrual` garante uma única comissão original.
7. **Uma consulta aberta por cliente** — garantia do model + índice único `open_client_id` (Prompt 10); estados finais gravam `NULL` e liberam nova solicitação.
8. **Uma consulta ativa por atendente** — garantia do model + índice único `active_attendant_id`; `requested` não bloqueia o atendente.
9. **Transições idempotentes e auditadas** — aceite/recusa/cancelamento/encerramento repetidos não duplicam efeitos nem auditoria; toda mudança grava `from`/`to`/`reason` em `audit_logs`.
10. **Mensagens tipadas com snapshot** — mensagens `tarot_card` guardam `deck_name`, `card_name` e `image_path` (snapshot) e referenciam `tarot_card_id` de forma opcional (`nullOnDelete`), preservando o histórico mesmo que a carta/baralho seja desativado. Somente consultas `active` aceitam mensagens, e somente o atendente escalado envia cartas.
11. **Candidatura ≠ acesso** — aprovar `attendant_applications` não cria `users` nem papel de atendente; revisão registra `reviewed_by`, `reviewed_at`, `review_started_at` e `status_changed_at`, com histórico em `audit_logs`.
12. **Imagens fora do banco** — apenas `image_path`, `image_mime`, `image_size` e `image_checksum` no MySQL; binário em storage privado (`tarot_private`).

## 4. Política de exclusão e snapshot de imagem

- **Preferir arquivar/inativar** (`is_active = false`) a apagar `tarot_decks`/`tarot_cards` usados em histórico.
- **FKs `restrictOnDelete`** em relações que preservam histórico (consultas, mensagens, ledgers, aplicações, auditoria) impedem remoção acidental de registros referenciados.
- **`nullOnDelete`** em `consultation_messages.tarot_card_id`: se uma carta for removida, a mensagem mantém seus snapshots e apenas perde o vínculo.
- **Snapshot de imagem**: no envio de uma carta (Prompt 11), o servidor copia o arquivo para um caminho imutável e grava esse caminho em `consultation_messages.image_path` (junto de `deck_name`/`card_name`). A exibição usa rota autorizada, nunca o caminho do catálogo.

## 5. Decisões de modelagem

| Tema | Decisão |
|---|---|
| Papéis | `users.role` string + enum PHP (`UserRole`), sem pacote de permissões |
| Comissão | `commission_rate_bps` em basis points inteiros (0–10000) |
| Parâmetros configuráveis | tabela `settings` (valor do crédito, saldo mínimo etc.); preços por minuto ficam em `attendant_modality_prices` |
| Estados | enums string-backed (`app/Enums`) |
| Ciclo da consulta | transições somente via `ConsultationLifecycleManager`, estados finais terminais e travas únicas `open_client_id`/`active_attendant_id` |
| Monetário | inteiros de centavos; formatação apenas na apresentação |
| Imutabilidade | ledgers/auditoria sem `updated_at`; `UPDATED_AT = null` nos modelos |

---

## 6. Entregas desta etapa

- 13 migrations, 8 enums, 13 modelos, 12 factories, seeder de demonstração.
- `tests/Feature/DataModelTest.php` valida casts, relacionamentos, snapshot e imutabilidade.
- Prompt 10: migration de travas/presença, transições idempotentes, comandos
  agendados de expiração/interrupção e `tests/Feature/ConsultationLifecycleTest.php`
  (ver `docs/CONSULTATION_STATE_MACHINE.md`).
- Migrations aplicadas sobre o banco existente **sem apagar dados**; suíte verde.

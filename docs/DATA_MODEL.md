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
| `timestamps` | | |

Índices: `email` (unique), `role`.

### 2.2 `attendant_profiles` — perfil profissional

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK users (unique) | 1:1; cascadeOnDelete |
| `display_name` | string | nome público |
| `bio` | text nullable | apresentação |
| `photo_path` | string nullable | foto pública opcional no disco `public` (`storage/app/public`) |
| `specialties` | json nullable | lista de especialidades |
| `commission_rate_bps` | unsignedInteger | comissão individual em **basis points** (0–10000) |
| `is_published` | boolean | status de publicação |
| `availability` | string(20) | `offline`/`online`; `busy` é derivado de consulta ativa |
| `timestamps` | | |

> `photo_path` e `availability` foram adicionados no Prompt 06 para o site
> público. O toggle manual online/offline no painel e as transições de estado são
> implementados no Prompt 08; `busy` nunca é persistido.

### 2.3 `consultations` — consultas/sessões

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `client_id` | FK users | restrictOnDelete |
| `attendant_id` | FK users | restrictOnDelete |
| `status` | string(20) | enum `ConsultationStatus`: `requested`, `active`, `ended`, `cancelled`, `expired` |
| `commission_rate_bps` | unsignedInteger nullable | **snapshot** da comissão no início oficial |
| `rate_credits_per_minute` | unsignedInteger nullable | **snapshot** da tarifa (preenchido no Prompt 15) |
| `credit_unit_price_cents` | unsignedBigInteger nullable | **snapshot** do preço do crédito (Prompt 15) |
| `started_at` / `ended_at` | timestamp nullable | controlados pelo servidor |
| `ended_reason` | string(40) nullable | enum `ConsultationEndReason` |
| `timestamps` | | |

Índices: `(attendant_id, status)`, `(client_id, created_at)`.

### 2.4 `consultation_messages` — mensagens do chat

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `consultation_id` | FK consultations | cascadeOnDelete |
| `sender_id` | FK users | restrictOnDelete |
| `type` | string(20) | enum `MessageType`: `text`, `tarot_card` |
| `body` | text nullable | conteúdo textual (ou legenda da carta) |
| `tarot_card_id` | FK tarot_cards nullable | referência quando aplicável; nullOnDelete |
| `deck_name` | string nullable | **snapshot** nome do baralho |
| `card_name` | string nullable | **snapshot** nome da carta |
| `image_path` | string nullable | **snapshot** caminho privado da imagem enviada |
| `timestamps` | | |

Índice: `(consultation_id, created_at)`.

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
| `is_active` | boolean | |
| `timestamps` | | |

### 2.7 `wallets` — carteira de créditos

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK users (unique) | 1:1; cascadeOnDelete |
| `balance_cents` | unsignedBigInteger | **cache** do livro-razão; nunca editável livremente |
| `timestamps` | | |

### 2.8 `credit_ledger_entries` — lançamentos de crédito/débito (imutável)

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `wallet_id` | FK wallets | restrictOnDelete |
| `type` | string(20) | enum `LedgerEntryType`: `credit`, `debit` |
| `amount_cents` | unsignedBigInteger | valor movimentado |
| `balance_after_cents` | unsignedBigInteger | saldo resultante |
| `reference_type` / `reference_id` | morphs nullable | origem (pagamento, consulta…) |
| `idempotency_key` | string unique | garante idempotência |
| `created_at` | timestamp | **sem** `updated_at` (imutável) |

Índice: `(wallet_id, created_at)`.

### 2.9 `payments` — recargas/pagamentos

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK users | restrictOnDelete |
| `provider` | string(40) | ex.: `pix_sandbox` |
| `provider_payment_id` | string nullable unique | referência externa |
| `amount_cents` | unsignedBigInteger | valor pago |
| `credits` | unsignedInteger | créditos concedidos |
| `credit_unit_price_cents` | unsignedBigInteger | snapshot do preço na compra |
| `status` | string(20) | enum `PaymentStatus`: `pending`, `confirmed`, `expired`, `failed`, `refunded` |
| `raw_status` | string nullable | status bruto do provedor |
| `idempotency_key` | string unique | |
| `paid_at` | timestamp nullable | |
| `timestamps` | | |

Índice: `(user_id, status)`.

### 2.10 `attendant_applications` — candidaturas "Trabalhe conosco"

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `name` / `email` | string | dados de contato |
| `phone` | string(30) nullable | |
| `experience_summary` | text nullable | campo profissional mínimo |
| `message` | text nullable | motivação |
| `status` | string(20) | enum `ApplicationStatus`: `pending`, `under_review`, `approved`, `rejected` |
| `reviewed_by` | FK users nullable | administrador revisor; nullOnDelete |
| `review_notes` | text nullable | notas internas |
| `submitted_at` / `reviewed_at` | timestamp nullable | datas de envio/revisão |
| `timestamps` | | |

Índices: `status`, `email`. **Aprovação não concede acesso automaticamente.**

### 2.11 `commission_ledger_entries` — comissão por consulta (imutável)

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `attendant_id` | FK users | restrictOnDelete |
| `consultation_id` | FK consultations nullable | nullable p/ ajustes |
| `commission_rate_bps` | unsignedInteger | percentual aplicado (snapshot) |
| `base_amount_cents` | unsignedBigInteger | base monetária cobrada |
| `commission_amount_cents` | unsignedBigInteger | valor calculado |
| `type` | string(20) | enum `CommissionEntryType`: `accrual`, `reversal`, `adjustment` |
| `reason` | text nullable | motivo de ajuste |
| `idempotency_key` | string unique | |
| `created_at` | timestamp | **sem** `updated_at` (imutável) |

Índices: `(attendant_id, created_at)`, `consultation_id`.

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
4. **Idempotência** — `idempotency_key` único em lançamentos e pagamentos evita duplicidade em webhooks/reprocessamento.
5. **Snapshot de comissão** — `consultations.commission_rate_bps` é copiado de `attendant_profiles.commission_rate_bps` no início oficial da sessão; alterações futuras na taxa não afetam consultas passadas.
6. **Comissão sobre o valor cobrado** — `base_amount_cents` é o valor monetário efetivamente cobrado na consulta; o cálculo (Prompt 16) ocorre no servidor e grava `commission_amount_cents` imutável.
7. **Uma consulta ativa por atendente** — garantia de aplicação (Prompt 10), apoiada pelo índice `(attendant_id, status)`.
8. **Mensagens tipadas com snapshot** — mensagens `tarot_card` guardam `deck_name`, `card_name` e `image_path` (snapshot) e referenciam `tarot_card_id` de forma opcional (`nullOnDelete`), preservando o histórico mesmo que a carta/baralho seja desativado.
9. **Candidatura ≠ acesso** — aprovar `attendant_applications` não cria `users` nem papel de atendente; revisão registra `reviewed_by` e `reviewed_at`.
10. **Imagens fora do banco** — apenas `image_path`/metadata no MySQL; binário em storage privado.

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
| Parâmetros configuráveis | tabela `settings` (preço do crédito, tarifa por minuto etc.) |
| Estados | enums string-backed (`app/Enums`) |
| Monetário | inteiros de centavos; formatação apenas na apresentação |
| Imutabilidade | ledgers/auditoria sem `updated_at`; `UPDATED_AT = null` nos modelos |

---

## 6. Entregas desta etapa

- 13 migrations, 8 enums, 13 modelos, 12 factories, seeder de demonstração.
- `tests/Feature/DataModelTest.php` valida casts, relacionamentos, snapshot e imutabilidade.
- Migrations aplicadas sobre o banco existente **sem apagar dados**; suíte verde.

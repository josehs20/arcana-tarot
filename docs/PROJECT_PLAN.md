# Arcana Tarot — Plano do Projeto

> Documento produzido na etapa **Prompt 00 — Análise do ambiente e plano**.
> Data da inspeção: **2026-10-20**.
> Este documento **não implementa funcionalidades**: registra arquitetura, módulos, decisões, riscos e ordem de execução do MVP.

---

## 1. Resumo executivo

O Arcana Tarot será um **monolito modular em Laravel 13** para consultas de Tarot por chat em tempo real, com três perfis (administrador, atendente e cliente), catálogo de baralhos/cartas, candidaturas de profissionais, carteira de créditos pré-pagos com livro-razão imutável, cobrança por tempo controlada pelo servidor e comissão percentual por atendente com taxa *snapshot*.

O repositório já contém um esqueleto Laravel 13 funcional, **sem código de domínio**. A estratégia é evoluir esse esqueleto incrementalmente, na ordem dos prompts 01–20, sem reescrever a fundação e mantendo cada etapa testada.

Princípios inegociáveis (derivados de `.cursor/rules/`):

1. O **servidor** é a fonte oficial de preço, saldo, duração, status e identidade.
2. Dinheiro em **unidades inteiras de centavos** (nunca `float`).
3. Créditos e comissões em **lançamentos imutáveis e auditáveis**, com idempotência.
4. Conversas e imagens de cartas só são acessíveis por **rotas autorizadas** no servidor.
5. Candidatura aprovada **não** cria acesso de atendente automaticamente.
6. Nenhum pagamento real no MVP: PIX apenas em **modo de teste**.

---

## 2. Estado atual do repositório (inspeção real)

### 2.1 Ambiente verificado

| Item | Resultado da inspeção |
|---|---|
| PHP (CLI host) | **8.5.4** (NTS) |
| Composer | **2.10.2** |
| Laravel Framework | **13.38.0** |
| Node.js | **22.22.1** |
| npm | **9.2.0** |
| Docker | **29.9.0** |
| Docker Compose | **v5.6.0** (daemon ativo) |
| Sistema operacional | Linux |
| Git | **não inicializado** (`fatal: not a git repository`) |

**Extensões PHP disponíveis no host:** ctype, curl, fileinfo, filter, json, libxml, mbstring, openssl, PDO, Phar, posix, random, readline, session, shmop, SimpleXML, sockets, sodium, tokenizer, xml, xmlreader, xmlwriter, xsl, zip, zlib, entre outras.

**Extensões PHP ausentes no host (relevantes):**

| Extensão | Necessária para | Situação |
|---|---|---|
| `pdo_sqlite` / `sqlite3` | Testes e `.env` atual (SQLite) | **AUSENTE** |
| `pdo_mysql` | MySQL 8.4 (stack pretendida) | **AUSENTE** |
| `redis` (phpredis) | `REDIS_CLIENT=phpredis`, cache/filas Reverb | **AUSENTE** |
| `bcmath` | cálculos decimais auxiliares | **AUSENTE** |
| `intl` | formatação/localização pt-BR | **AUSENTE** |
| `gd` | geração/redimensionamento de imagens | **AUSENTE** |

Consequência comprovada:

- `php artisan db:show` → `PDOException: could not find driver`.
- `php artisan test` → **2 testes passam** (2/2, 2 assertions), mas apenas porque `tests/Feature/ExampleTest.php` faz `GET /` sem tocar no banco e os drivers de sessão/cache/fila estão em `array/sync` no `phpunit.xml`.

### 2.2 Arquivos e estrutura encontrados

| Área | Conteúdo atual |
|---|---|
| `app/` | `Http/Controllers/Controller.php`, `Models/User.php`, `Providers/AppServiceProvider.php` |
| `routes/` | `web.php` (rota `/` → `welcome`), `console.php` (comando `inspire`) |
| `database/` | `database.sqlite`, `factories/UserFactory.php`, migrations padrão (`users`, `cache`, `jobs`), `seeders/DatabaseSeeder.php` |
| `tests/` | `Feature/ExampleTest.php`, `Unit/ExampleTest.php`, `TestCase.php` |
| `resources/` | `css/app.css`, `js/app.js`, `views/welcome.blade.php` |
| `public/` | `favicon.ico`, `.htaccess`, `index.php`, `robots.txt` |
| `config/` | `app`, `auth`, `cache`, `database`, `filesystems`, `logging`, `mail`, `queue`, `services`, `session` |
| `docs/` | **não existia** (criado nesta etapa) |
| Docker | **nenhum** `Dockerfile`, `docker-compose.yml` ou equivalente |
| `node_modules/` | **ausente** (assets não instalados/compilados) |
| `.cursor/rules/` na raiz | **ausente** — as regras existem apenas em `arcana-tarot-prompts/.cursor/rules/` |

### 2.3 Dependências diretas instaladas (`composer show --direct`)

| Pacote | Versão | Uso |
|---|---|---|
| `laravel/framework` | 13.38.0 | Framework |
| `laravel/tinker` | 3.0.2 | REPL |
| `fakerphp/faker` | 1.24.1 | Factories/seeders |
| `laravel/pint` | 1.32.1 | Formatação |
| `mockery/mockery` | 1.6.15 | Mocks |
| `nunomaduro/collision` | 8.9.5 | Erros CLI |
| `phpunit/phpunit` | 12.5.40 | Testes |
| `laravel/pail` | 1.2.7 | Logs |
| `laravel/pao` | 1.1.5 | Saída de testes otimizada para agente |

Observação: os testes emitem saída JSON (`{"tool":"phpunit","result":"passed",...}`) por causa do `laravel/pao`.

### 2.4 Configuração atual (`php artisan about`)

| Chave | Valor atual |
|---|---|
| Application Name | `Laravel` |
| Environment | `local` |
| Debug Mode | ENABLED |
| URL | `http://localhost:8000` |
| Timezone | `UTC` |
| Locale | `en` |
| Broadcasting | `log` |
| Cache | `database` |
| Database | `sqlite` |
| Mail | `log` |
| Queue | `database` |
| Session | `database` |
| `public/storage` | NOT LINKED |

---

## 3. Stack pretendida × estado atual

| Componente | Pretendido | Estado atual | Ação necessária |
|---|---|---|---|
| Laravel | 13 | **13.38.0** | ✔ Atende |
| PHP | 8.4 | **8.5.4** no host | Fixar **8.4** na imagem Docker (Prompt 02); 8.5 é compatível com Laravel 13 (`^8.3`) |
| Docker Compose | sim | **ausente** | Criar em Prompt 02 |
| Nginx | sim | **ausente no projeto** (imagem `nginx:1.27-alpine` já baixada) | Criar em Prompt 02 |
| MySQL | 8.4 LTS | **SQLite** por padrão; imagem `mysql:8.4` já baixada | Configurar em Prompt 02/03 |
| Redis | sim | Config presente (`REDIS_CLIENT=phpredis`), **sem servidor nem extensão no host**; imagem `redis:7-alpine` já baixada | Subir no Compose e instalar cliente no container (Prompt 02) |
| Laravel Reverb | sim | **não instalado**; `BROADCAST_CONNECTION=log` | Instalar/configurar em Prompt 12 |
| Blade | sim | **presente** (`welcome.blade.php`) | ✔ Base pronta |
| Bootstrap 5.3 | sim | **Tailwind CSS 4** no `package.json`/`vite.config.js` | Prompt 05: introduzir Bootstrap 5.3 e decidir sobre o Tailwind existente (ver D-013) |
| JavaScript | sim | `resources/js/app.js` mínimo | Evoluir nos prompts de layout/chat |
| Laravel Echo + cliente Pusher | sim | **ausente** | Prompt 12 |
| Vite | sim | **Vite 8** + `laravel-vite-plugin` 3.1 | ✔ Atende |
| PHPUnit | sim (ou Pest) | **PHPUnit 12.5.40** | ✔ Atende (manter PHPUnit; Pest não instalado) |

### 3.1 Divergências e limitações reais

1. **Driver de banco no host**: sem `pdo_sqlite` e `pdo_mysql`, qualquer teste com `RefreshDatabase` ou acesso real ao banco falha no host. Os testes atuais só passam porque não consultam o banco.
2. **Redis no host**: sem extensão `redis` e sem cliente alternativo (`predis`) instalado. `REDIS_CLIENT=phpredis` não funciona fora do container.
3. **PHP 8.5 × 8.4**: o host tem 8.5; a stack pretendida cita 8.4. Não há incompatibilidade com Laravel 13, mas a paridade de ambientes exige pinar 8.4 no Docker.
4. **Tailwind × Bootstrap**: o esqueleto Laravel 13 vem com Tailwind 4. A stack pretendida exige Bootstrap 5.3. É preciso uma decisão explícita no Prompt 05 para não misturar dois sistemas de utilidades sem controle.
5. **Sem versionamento**: não há repositório Git; commits por etapa (exigidos pelo README do pacote) dependem de `git init` no Prompt 01.
6. **Sem Docker/CI**: não há ambiente reproduzível ainda; os testes rodam no host com limitações acima.
7. **Fuso/locale**: `UTC`/`en`; o produto é pt-BR. Ajuste previsto no Prompt 01/05 (decisão Q-07).
8. **`public/storage` não linkado**: imagens de cartas devem ir para disco **privado**, então o link público pode nem ser necessário (ver D-007).

---

## 4. Arquitetura proposta

### 4.1 Estilo arquitetural

**Monolito modular** com separação por responsabilidade dentro do Laravel:

```
HTTP (rotas, controllers, form requests, middleware)
        │
Políticas / Gates (autorização server-side)
        │
Serviços de domínio (regras de negócio transacionais e reutilizáveis)
        │
Modelos Eloquent + Enums de estado
        │
MySQL 8.4 (persistência)  ·  Redis (cache/filas)  ·  Storage privado (imagens)
        │
Eventos de domínio → Broadcasting (Reverb) + Jobs/Filas
```

Regras críticas (cobrança, créditos, comissão, transições de estado) ficam em **serviços de domínio**, nunca em controllers ou JavaScript, para serem reutilizáveis por HTTP, filas e eventos.

### 4.2 Convenções de diretórios previstas

```
app/
├── Enums/                     # UserRole, AttendantStatus, ConsultationStatus,
│                              # PaymentStatus, ApplicationStatus, MessageType
├── Http/
│   ├── Controllers/           # Admin/, Attendant/, Client/, Public/
│   ├── Middleware/            # EnsureRole, EnsureAttendantOnline...
│   └── Requests/              # validação (Form Requests)
├── Models/                    # User, AttendantProfile, AttendantApplication,
│                              # TarotDeck, TarotCard, CardImageSnapshot,
│                              # Consultation, ConsultationMessage, Wallet,
│                              # CreditLedgerEntry, Payment, CommissionLedgerEntry,
│                              # Setting, AuditLog
├── Policies/                  # ConsultationPolicy, MessagePolicy, WalletPolicy...
├── Services/                  # BillingService, CreditLedgerService, CommissionService,
│                              # ConsultationService, PaymentService, CardMessageService
├── Events/                    # MessageSent, ConsultationStateChanged...
├── Jobs/                      # BillingTick, ExpireConsultation...
└── Support/                   # Money (centavos), Clock abstractions
database/
├── migrations/
├── factories/
└── seeders/
docs/                          # PROJECT_PLAN.md, DECISIONS.md (+ docs das próximas etapas)
routes/
├── web.php
├── channels.php               # autorização de canais privados Reverb
└── console.php
resources/
├── views/                     # layouts, components, public, admin, attendant, client
├── css/
└── js/
tests/
├── Unit/                      # regras de domínio (dinheiro, cobrança, comissão)
└── Feature/                   # fluxos HTTP, autorização, chat, carteira
```

### 4.3 Diagrama de contexto do MVP

```
┌──────────┐   navegador (Blade + Bootstrap + Echo)
│ Cliente  │───────────────┐
└──────────┘               │
┌──────────┐               ▼
│ Atendente│──────►  Laravel 13 (Nginx + PHP-FPM)
└──────────┘               │
┌──────────┐               ├── MySQL 8.4  (dados, chat, ledgers)
│  Admin   │───────────────┤── Redis      (cache, filas, presença)
└──────────┘               ├── Reverb     (WebSocket)
                           └── Storage privado (imagens de cartas)
                                   ▲
                                   │ webhook (somente modo teste)
                             Provedor PIX (sandbox)
```

---

## 5. Modelo de domínio (entidades previstas)

> Nomes são propostas para o Prompt 03 e podem ser ajustados, preservando a semântica abaixo.

### 5.1 Usuários e atendentes

| Entidade | Campos-chave | Observações |
|---|---|---|
| `users` | `name`, `email`, `password`, `role`, `is_active`, timestamps | `role` ∈ {admin, attendant, client}. Sem `spatie/laravel-permission` (ver D-003) |
| `attendant_profiles` | `user_id`, `display_name`, `bio`, `specialties`, `status`, `commission_rate_bps` | `status` ∈ {online, offline, busy}; `busy` **derivado** de consulta ativa (D-008) |
| `attendant_applications` | `name`, `email`, `phone`, `experience`, `message`, `status`, `reviewed_by`, `reviewed_at`, `review_notes` | `status` ∈ {pending, approved, rejected}; aprovação **não** cria usuário (D-010) |

### 5.2 Catálogo

| Entidade | Campos-chave | Observações |
|---|---|---|
| `tarot_decks` | `name`, `slug`, `description`, `is_active` | Gerenciado pelo admin |
| `tarot_cards` | `deck_id`, `name`, `slug`, `meaning`, `image_path`, `is_active`, `sort_order` | `image_path` em **disco privado**; nunca exposto diretamente |
| `card_image_snapshots` | `tarot_card_id`, `path`, `checksum`, `created_at` | Cópia imutável gerada no envio da carta (D-007) |

### 5.3 Consultas e chat

| Entidade | Campos-chave | Observações |
|---|---|---|
| `consultations` | `client_id`, `attendant_id`, `status`, `accepted_at`, `started_at`, `ended_at`, `rate_credits_per_minute` (snapshot), `credit_unit_price_cents` (snapshot), `commission_rate_bps` (snapshot), `ended_reason` | Estados em §6.3 |
| `consultation_messages` | `consultation_id`, `sender_id`, `type`, `body`, `card_snapshot_id`, `created_at` | `type` ∈ {text, card}. Persistir **antes** de transmitir (D-007) |

### 5.4 Financeiro

| Entidade | Campos-chave | Observações |
|---|---|---|
| `wallets` | `user_id`, `balance_cents` (cache) | Saldo é **cache** do livro-razão; nunca editável livremente |
| `credit_ledger_entries` | `wallet_id`, `type` (credit/debit), `amount_cents`, `balance_after_cents`, `reference_type`, `reference_id`, `idempotency_key`, `created_at` | **Imutável**; correções = novos lançamentos |
| `payments` | `user_id`, `provider`, `provider_payment_id`, `amount_cents`, `credits`, `status`, `paid_at`, `raw_status` | `status` ∈ {pending, confirmed, expired, failed}; confirmação **somente** por webhook validado no servidor |
| `commission_ledger_entries` | `attendant_id`, `consultation_id`, `base_amount_cents`, `commission_rate_bps`, `commission_amount_cents`, `status`, `idempotency_key`, `created_at` | **Imutável**; sem saque/pagamento no MVP |
| `settings` | `key`, `value`, `type` | Parâmetros configuráveis (preço do crédito, tarifa por minuto, etc.) |

### 5.5 Auditoria

| Entidade | Campos-chave | Observações |
|---|---|---|
| `audit_logs` | `actor_id`, `action`, `subject_type`, `subject_id`, `metadata` (sem PII/segredos), `created_at` | Revisões administrativas, alterações de catálogo, acessos sensíveis |

---

## 6. Regras de negócio críticas

### 6.1 Perfis e autorização

- **Administrador**: gerencia catálogo, candidaturas, parâmetros e indicadores; acesso a conversas **somente** conforme política explícita (Q-06).
- **Atendente**: vê apenas consultas atribuídas a ele; alterna `online`/`offline`; `busy` é automático.
- **Cliente**: vê apenas suas próprias consultas, mensagens e carteira.
- Autorização **sempre** no servidor via Policies/Gates e canais privados; esconder botão não é controle de acesso.

### 6.2 Status do atendente

```
offline ──(atendente ativa)──► online ──(consulta aceita)──► busy
   ▲                              ▲                            │
   └──────(atendente desativa)────┴────(consulta encerrada)────┘
```

- `busy` **nunca** é escolhido manualmente: é derivado da existência de consulta ativa.
- No MVP, **1 consulta ativa por atendente** (D-009).

### 6.3 Estados da consulta

```
requested ──(atendente aceita)──► active ──(encerrada)──► ended
     │                                │
     ├──(cancelada pelo cliente)──────┤
     └──(expirada/recusada)───────────┴──► cancelled / expired
```

- A cobrança só começa quando o **servidor** confirma `active` (aceite do atendente).
- Transições explícitas, validadas no servidor; navegador nunca define estado/duração.
- Encerramento por: cliente, atendente, saldo insuficiente ou expiração por inatividade (política a confirmar — Q-13).
- Reconexão WebSocket **não** cria nova sessão nem duplica débitos.

### 6.4 Créditos e conversão crédito → dinheiro

Modelo proposto (a ser implementado no Prompt 13):

1. Todo valor monetário é armazenado em **centavos inteiros**.
2. Existe um parâmetro configurável `credit_unit_price_cents` = valor em centavos de **1 crédito**.
3. A cobrança por tempo usa `rate_credits_per_minute` (configurável) e gera débitos no livro-razão em créditos **e** o valor monetário correspondente.
4. **Conversão oficial**: `valor_monetario_cents = creditos_cobrados × credit_unit_price_cents`, arredondada para centavos no servidor.
5. `credit_unit_price_cents` e `rate_credits_per_minute` são **congelados na abertura da consulta** (snapshot), para que mudanças futuras não alterem histórico.
6. **Não se assume que 1 crédito = R$ 1,00.** O valor é definido pelo administrador.

> Valores iniciais de `credit_unit_price_cents` e `rate_credits_per_minute` **não serão fixados neste plano** (Q-02).

### 6.5 Cobrança por tempo

- Relógio oficial: **servidor** (timestamps de `accepted_at`/`started_at`/`ended_at`).
- Regra de arredondamento: **por minuto iniciado**, com cobrança mínima de 1 minuto; detalhes em Q-03.
- Débitos idempotentes por competência (ex.: minuto N da consulta), com chave de idempotência por consulta+minuto.
- Sem saldo → encerramento seguro e idempotente; **nunca** saldo negativo (D-005).
- Falha de conexão segue política explícita (não cobrar indefinidamente sem controle).

### 6.6 Comissão do atendente

- Percentual **individual**, configurável pelo admin por atendente; validado de **0 a 100**.
- Armazenamento proposto: **basis points inteiros** (`commission_rate_bps`, 0–10000) para evitar `float` (Q-04).
- **Snapshot** da taxa na abertura da consulta (`consultations.commission_rate_bps`).
- Base de cálculo: **valor monetário oficial da consulta** (não créditos brutos, não valores do navegador).
- `comissao_cents = round(base_amount_cents × commission_rate_bps / 10000)`.
- Lançamento em `commission_ledger_entries`, **imutável e idempotente**; estornos/correções = novos lançamentos auditados.
- **Sem pagamento/saque de comissão no MVP**: apenas registro e apresentação dos valores ganhos.

### 6.7 Candidaturas ("Trabalhe conosco")

- Formulário público com **coleta mínima** (nome, e-mail, telefone opcional, experiência, mensagem) e aviso de privacidade.
- Estados explícitos: `pending` → `approved` | `rejected`, com `reviewed_by` e `reviewed_at`.
- Aprovação **não** cria usuário nem papel de atendente; o admin cria o acesso separadamente (D-010).
- Acesso às candidaturas restrito a administradores; dados pessoais não vão para logs.

### 6.8 Catálogo e mensagens de carta

- Apenas o **atendente atribuído à consulta ativa** pode enviar carta no MVP.
- A mensagem de carta é um registro **tipado** persistido no MySQL (`type=card`) com referência a um **snapshot imutável** da imagem.
- Imagens ficam em **storage privado**; exibição por **rota autorizada** que valida participação na consulta.
- Nunca aceitar caminho/URL de imagem enviado pelo navegador.
- Inativação/edição da carta não altera mensagens históricas.

### 6.9 Segurança e privacidade

- Validação server-side de todos os dados; Form Requests + Policies.
- Sem segredos/PII/conteúdo de chat em logs.
- Canais Reverb/Echo privados com autorização de participação na consulta.
- Retenção e acesso administrativo a conversas definidos por política explícita (Q-06).
- Rate limiting em login, formulário público de candidatura e endpoints de imagem.

---

## 7. Módulos e ordem de execução

A ordem abaixo segue `arcana-tarot-prompts/prompts/`. Cada etapa deve ser concluída com testes verdes antes da seguinte.

### Prompt 01 — Fundação Laravel e qualidade do repositório

- **Objetivo**: consolidar a base do projeto e o versionamento.
- **Entregas**: `git init` + `.gitignore` revisado; locale/timezone pt-BR (`config/app.php`); nome do app; Pint configurado; scripts Composer de teste; README do projeto (substituindo o genérico do Laravel); `.cursor/rules/` copiado do pacote para a raiz; decisão sobre ambiente de testes.
- **Testes**: `php artisan test`; `vendor/bin/pint --test`.
- **Critério de saída**: testes atuais passando e repositório versionado.

### Prompt 02 — Docker e serviços locais

- **Objetivo**: ambiente reproduzível.
- **Entregas**: `Dockerfile` (PHP 8.4-fpm + extensões `pdo_mysql`, `redis`/predis, `intl`, `gd`, `bcmath`, `pcntl`, `sockets`), `docker-compose.yml` com app, nginx, mysql:8.4, redis:7, reverb e queue worker; `.env.example` com MySQL/Redis/Reverb; healthchecks; volumes persistentes.
- **Testes**: subir o stack; `php artisan migrate` no container; `php artisan test` no container.
- **Critério de saída**: aplicação acessível via Nginx e testes executando contra MySQL/ambiente Docker.

### Prompt 03 — Modelagem do banco de dados

- **Objetivo**: criar o esquema completo do MVP.
- **Entregas**: migrations/factories/seeders de usuários/roles, perfis de atendente, candidaturas, baralhos, cartas, snapshots de imagem, consultas, mensagens, carteira, livro-razão de créditos, pagamentos, livro-razão de comissão, settings e auditoria; índices e FKs.
- **Testes**: migrations em banco limpo; testes de constraints e relacionamentos.
- **Critério de saída**: `migrate:fresh --seed` reproduzível e testes de esquema verdes.

### Prompt 04 — Autenticação e autorização

- **Objetivo**: login/registro e controle de acesso por papel.
- **Entregas**: autenticação (Blade), enum de papel, middleware/Policies/Gates, proteção de rotas, rate limiting de login, cadastro de cliente.
- **Testes**: feature tests de login, acesso negado por papel, isolamento de dados.
- **Critério de saída**: cada perfil só acessa o permitido, validado no servidor.

### Prompt 05 — Layout visual e componentes Blade

- **Objetivo**: identidade visual e componentes reutilizáveis.
- **Entregas**: layout base, Bootstrap 5.3 via Vite, tokens de cor (azul-noite, roxo, dourado, marfim), tipografia (Cormorant/Cinzel + Inter/Manrope com fallback), componentes (nav, alerts, buttons, cards, empty states), acessibilidade e responsividade; decisão registrada sobre o Tailwind legado.
- **Testes**: smoke tests de renderização; verificação de assets compilados (`npm run build`).
- **Critério de saída**: `npm run build` sem erro e páginas responsivas.

### Prompt 06 — Site público, perfis e CTA "Trabalhe conosco"

- **Objetivo**: home pública e listagem/perfil de atendentes.
- **Entregas**: home, listagem de atendentes com status textual (online/offline/ocupado), perfil público, CTA visível para candidatura.
- **Testes**: feature tests de rotas públicas e exibição de status.
- **Critério de saída**: páginas públicas acessíveis e responsivas.

### Prompt 07 — Formulário público de candidatura e análise administrativa

- **Objetivo**: captar e revisar candidaturas.
- **Entregas**: formulário com validação e aviso de privacidade, persistência, painel admin de revisão com estados e auditoria do revisor; aprovação sem criar acesso.
- **Testes**: validação, submissão, autorização admin, transições de estado.
- **Critério de saída**: fluxo completo de candidatura com dados mínimos e auditoria.

### Prompt 08 — Painéis e status do atendente

- **Objetivo**: dashboards dos três perfis e controle de disponibilidade.
- **Entregas**: painel do atendente com toggle online/offline e status `busy` derivado; painel do cliente; painel admin inicial; transições controladas pelo servidor.
- **Testes**: transições de status, derivação de `busy`, autorização por painel.
- **Critério de saída**: status consistente e nunca manipulado pelo cliente.

### Prompt 09 — Cadastro de baralhos e cartas

- **Objetivo**: catálogo gerenciado pelo admin.
- **Entregas**: CRUD de baralhos/cartas, upload de imagem em disco privado, validação de mime/tamanho, inativação, seeders de demonstração.
- **Testes**: upload autorizado, rejeição de arquivos inválidos, isolamento de acesso.
- **Critério de saída**: catálogo gerenciável e imagens inacessíveis por URL direta.

### Prompt 10 — Sessões de consulta e regras de estado

- **Objetivo**: ciclo de vida da consulta.
- **Entregas**: solicitação, aceite, início oficial no servidor, encerramento, cancelamento, expiração; 1 consulta ativa por atendente; snapshots de tarifa/preço/comissão na abertura.
- **Testes**: máquina de estados, concorrência (duas aceitações), autorização de participantes.
- **Critério de saída**: nenhuma transição inválida e cobrança só após confirmação do servidor.

### Prompt 11 — Chat persistente, incluindo mensagens de carta

- **Objetivo**: histórico de mensagens e envio de cartas.
- **Entregas**: mensagens texto e carta persistidas, snapshot de imagem no envio, rota protegida de imagem, paginação/histórico, autorização por participação.
- **Testes**: persistência, autorização de leitura/escrita, snapshot imutável, acesso negado a não participantes.
- **Critério de saída**: histórico íntegro e imagens só via rota autorizada.

### Prompt 12 — Reverb e eventos em tempo real

- **Objetivo**: entrega em tempo real de mensagens e cartas.
- **Entregas**: instalação/configuração do Reverb, Echo + cliente Pusher, canais privados, eventos após persistência, autorização de canal.
- **Testes**: autorização de canal, emissão de evento, fallback/reconexão sem duplicar mensagens.
- **Critério de saída**: mensagens chegam em tempo real e o histórico permanece a fonte de verdade.

### Prompt 13 — Carteira e livro-razão de créditos

- **Objetivo**: créditos auditáveis.
- **Entregas**: wallet + `credit_ledger_entries` imutáveis, crédito/débito idempotentes, saldo como cache, conversão documentada crédito→centavos, prevenção de saldo negativo.
- **Testes**: idempotência, concorrência, consistência saldo×ledger, saldo insuficiente.
- **Critério de saída**: nenhum saldo editável sem lançamento correspondente.

### Prompt 14 — Pagamentos PIX em modo de teste

- **Objetivo**: recarga via PIX sem dinheiro real.
- **Entregas**: criação de cobrança sandbox, webhook validado, idempotência, liberação de créditos somente após confirmação do provedor, estados de pagamento.
- **Testes**: webhook válido/inválido, duplicidade, pagamento expirado, crédito liberado uma única vez.
- **Critério de saída**: créditos só entram após confirmação server-side do provedor.

### Prompt 15 — Cobrança por tempo e encerramento

- **Objetivo**: debitar corretamente pelo tempo de consulta.
- **Entregas**: job/rotina de cobrança idempotente por minuto iniciado, snapshot de tarifa, encerramento automático por saldo, política de falha/reconexão.
- **Testes**: relógio controlado, idempotência por minuto, encerramento por saldo zero, sem saldo negativo.
- **Critério de saída**: débitos corretos e reprodutíveis, independentes do navegador.

### Prompt 16 — Comissão por atendente e lançamentos auditáveis

- **Objetivo**: registrar comissões imutáveis.
- **Entregas**: taxa individual configurável (0–100%), snapshot por consulta, cálculo sobre valor oficial, ledger idempotente, painel de comissões (sem saque).
- **Testes**: cálculo com diferentes taxas, idempotência, imutabilidade, correção por novo lançamento.
- **Critério de saída**: comissão derivada da cobrança oficial e nunca editada retroativamente.

### Prompt 17 — Histórico, indicadores e administração

- **Objetivo**: visão administrativa e históricos.
- **Entregas**: histórico de consultas/mensagens por perfil, indicadores (consultas, créditos, comissões), administração de usuários/atendentes, filtros e paginação.
- **Testes**: autorização, isolamento de dados, agregações corretas.
- **Critério de saída**: cada perfil vê apenas o permitido; números batem com os ledgers.

### Prompt 18 — Segurança, privacidade e auditoria

- **Objetivo**: endurecer o MVP.
- **Entregas**: revisão de Policies, rate limiting, headers de segurança, política de acesso admin a conversas, retenção, logs sem PII, auditoria de ações sensíveis, validação de uploads.
- **Testes**: tentativas de acesso indevido, CSRF, upload malicioso, vazamento de dados.
- **Critério de saída**: checklist de segurança atendido e testado.

### Prompt 19 — Testes integrados e correções

- **Objetivo**: validar fluxos ponta a ponta.
- **Entregas**: cenários integrados (candidatura → aprovação → criação de atendente; recarga teste → consulta → chat/carta → cobrança → comissão), correções.
- **Testes**: suíte completa no ambiente Docker.
- **Critério de saída**: suíte verde e fluxos críticos cobertos.

### Prompt 20 — Documentação e preparação para implantação

- **Objetivo**: documentar e preparar deploy (sem executar produção).
- **Entregas**: README operacional, variáveis de ambiente, runbook, checklist de deploy, notas de segurança, documentação da conversão crédito→dinheiro e das regras de cobrança/comissão.
- **Testes**: validação de documentação e comandos de build.
- **Critério de saída**: projeto documentado e reproduzível.

---

## 8. Estratégia de testes

| Nível | Escopo | Ferramenta |
|---|---|---|
| Unitário | Dinheiro (centavos), conversão crédito→centavos, arredondamento, cálculo de comissão, máquina de estados | PHPUnit (`tests/Unit`) |
| Feature | Rotas, autenticação/autorização, formulários, uploads, chat, carteira, webhooks | PHPUnit + `RefreshDatabase` (`tests/Feature`) |
| Integração | Fluxos completos entre módulos e banco real (MySQL no Docker) | PHPUnit no container |
| Frontend | `npm run build`; smoke tests de renderização | Vite + PHPUnit |

Diretrizes:

- **Nunca** remover ou enfraquecer testes para obter sucesso.
- Relógio e provedor PIX devem ser **injetáveis/fakeáveis** para testes determinísticos.
- Testes de concorrência/idempotência obrigatórios em créditos, cobrança, comissão e aceite de consulta.
- No host atual (sem `pdo_*`), testes com banco devem rodar no container Docker (Prompt 02) ou após instalar as extensões.

---

## 9. Riscos e mitigações

| # | Risco | Impacto | Mitigação |
|---|---|---|---|
| R-01 | Extensões PHP ausentes no host (`pdo_sqlite`, `pdo_mysql`, `redis`) | Alto — testes com banco falham | Executar testes no container; instalar extensões no host; documentar |
| R-02 | Tailwind 4 × Bootstrap 5.3 | Médio — conflito visual/técnico | Decisão explícita no Prompt 05; remover Tailwind se não for usado |
| R-03 | PHP 8.5 no host × 8.4 pretendido | Baixo | Pinar `php:8.4` no Docker; validar compatibilidade |
| R-04 | Provedor PIX indefinido | Alto — bloqueia Prompt 14 | Decidir provedor sandbox antes do Prompt 14 (Q-01) |
| R-05 | Conversão crédito→dinheiro e tarifas indefinidas | Alto — bloqueia carteira/cobrança | Definir `credit_unit_price_cents` e `rate_credits_per_minute` antes do Prompt 13 (Q-02) |
| R-06 | Regra de arredondamento ambígua | Alto — disputas de cobrança | Documentar e testar (Q-03) |
| R-07 | Concorrência em aceite de consulta/saldo | Alto — dupla cobrança/sessão | Transações + locks + idempotência |
| R-08 | Reverb/Redis mal configurados | Médio — chat sem tempo real | Healthchecks, canais autorizados, fallback de polling |
| R-09 | Imagens de cartas expostas | Alto — privacidade | Disco privado + rota autorizada + snapshot |
| R-10 | Acesso admin indevido a conversas | Alto — LGPD | Política explícita + auditoria (Q-06) |
| R-11 | Sem Git | Médio — perda de histórico | `git init` no Prompt 01 e commits por etapa |
| R-12 | Fuso/locale incorretos | Médio — cobrança/horários | Definir `America/Sao_Paulo` e pt-BR (Q-07) |

---

## 10. Critérios de conclusão desta etapa (Prompt 00)

- [x] Repositório e ambiente inspecionados antes de qualquer alteração de código.
- [x] Stack atual comparada com a pretendida.
- [x] `docs/PROJECT_PLAN.md` criado com arquitetura, módulos, decisões, riscos e ordem.
- [x] `docs/DECISIONS.md` criado com decisões iniciais e dúvidas.
- [x] Limitações reais reportadas (extensões PHP, Tailwind×Bootstrap, ausência de Docker/Git).
- [x] **Nenhum módulo implementado**; apenas documentação criada.
- [x] Arquivos existentes preservados (nenhum sobrescrito).

---

## 11. Próximo passo recomendado

**Prompt 01 — Fundação Laravel e qualidade do repositório**, iniciando por:

1. `git init` e primeiro commit do estado atual;
2. cópia de `arcana-tarot-prompts/.cursor/rules/*.mdc` para `.cursor/rules/`;
3. ajuste de locale/timezone e nome da aplicação;
4. configuração de Pint e scripts de teste;
5. README do projeto.

Antes do Prompt 13/14, confirmar as decisões **Q-01**, **Q-02** e **Q-03** (provedor PIX, preço do crédito/tarifa e arredondamento).


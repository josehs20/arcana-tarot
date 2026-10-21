# Arcana Tarot — Registro de Decisões

> Documento produzido na etapa **Prompt 00** (2026-10-20).
> Formato leve de ADR: cada decisão tem ID, status, contexto, decisão e consequências.
> Status possíveis: **Aceita**, **Proposta**, **Pendente**.

---

## 1. Decisões iniciais

### D-001 — Monolito modular Laravel 13

- **Status**: Aceita
- **Contexto**: MVP com três perfis, chat, carteira e catálogo; equipe pequena; stack já definida.
- **Decisão**: manter um único aplicativo Laravel 13, organizado em módulos por responsabilidade (`Http`, `Services`, `Models`, `Policies`, `Events`, `Jobs`), sem separação em microsserviços.
- **Consequências**: simplicidade operacional e transações locais; exige disciplina de camadas para não concentrar regra em controllers.

### D-002 — Dinheiro em centavos inteiros

- **Status**: Aceita
- **Contexto**: regras do projeto proíbem `float` para dinheiro.
- **Decisão**: todos os valores monetários são armazenados e calculados em **centavos inteiros** (`BIGINT`/`unsignedBigInteger`). Formatação para exibição ocorre apenas na camada de apresentação.
- **Consequências**: evita erro de arredondamento binário; exige helpers de formatação e testes de borda.

### D-003 — Papéis com enum nativo + Policies (sem pacote de permissões)

- **Status**: Aceita
- **Contexto**: três perfis fixos no MVP; a stack pretendida não inclui `spatie/laravel-permission`.
- **Decisão**: `users.role` como enum (`admin`, `attendant`, `client`), autorização por **Policies/Gates** e middleware de papel.
- **Consequências**: menos dependências e complexidade; se o produto evoluir para múltiplos papéis por usuário, será necessária migração.

### D-004 — Créditos com livro-razão imutável e saldo como cache

- **Status**: Aceita
- **Contexto**: regra proíbe saldo como campo livremente editável.
- **Decisão**: toda movimentação gera `credit_ledger_entries` imutável com `idempotency_key`; `wallets.balance_cents` é apenas cache atualizado na mesma transação.
- **Consequências**: auditoria e reprocessamento seguros; requer rotina de reconciliação e testes de idempotência.

### D-005 — Cobrança por minuto iniciado, controlada pelo servidor

- **Status**: Proposta (depende de Q-03)
- **Contexto**: cobrança por tempo sem confiar no navegador.
- **Decisão**: o servidor calcula a duração a partir de `accepted_at`/`ended_at`, cobra **por minuto iniciado** (mínimo de 1 minuto) e grava débitos idempotentes por consulta+minuto. Sem saldo, a consulta é encerrada de forma idempotente e **nunca** fica negativa.
- **Consequências**: previsível para o cliente; exige job/scheduler e relógio testável.

### D-006 — Comissão percentual individual com snapshot

- **Status**: Aceita
- **Contexto**: comissão por atendente, configurável, sem valores arbitrários fixos.
- **Decisão**: taxa individual em **basis points inteiros** (0–10000) configurada pelo admin; `commission_rate_bps` é copiada para a consulta na abertura (snapshot); comissão calculada sobre o **valor monetário oficial** e registrada em `commission_ledger_entries` imutável e idempotente. Sem saque/pagamento no MVP.
- **Consequências**: histórico imutável; mudanças de taxa só afetam consultas futuras.

### D-007 — Imagens de cartas em storage privado com snapshot no envio

- **Status**: Aceita
- **Contexto**: catálogo não pode ser exposto por caminho público; histórico precisa sobreviver a edições.
- **Decisão**: imagens em disco **privado**; ao enviar uma carta, o servidor cria um `card_image_snapshots` imutável; a exibição usa **rota autorizada** que valida a participação na consulta. A mensagem de carta (`type=card`) é persistida **antes** do broadcast.
- **Consequências**: privacidade e consistência histórica; custo de armazenamento de snapshots.

### D-008 — `busy` derivado de consulta ativa

- **Status**: Aceita
- **Contexto**: status do atendente não pode ser manipulado pelo cliente.
- **Decisão**: `online`/`offline` são controlados pelo atendente autenticado; `busy` é **derivado** da existência de consulta ativa e nunca escolhido manualmente.
- **Consequências**: consistência de disponibilidade; exige cálculo/atualização confiável no servidor.

### D-009 — Uma consulta ativa por atendente (MVP)

- **Status**: Aceita
- **Contexto**: regra explícita do projeto.
- **Decisão**: garantir no servidor, com transação/bloqueio, no máximo **uma consulta em estado ativo por atendente**.
- **Consequências**: aceite concorrente deve falhar para o segundo solicitante; simplifica cobrança e presença.

### D-010 — Aprovação de candidatura não cria acesso

- **Status**: Aceita
- **Contexto**: dados públicos de candidatura e risco de escalada de privilégio.
- **Decisão**: aprovar uma candidatura altera apenas o estado da candidatura (`approved`), registrando revisor e data. A criação do usuário/atendente é uma ação administrativa separada e auditada.
- **Consequências**: fluxo com uma etapa manual a mais; elimina criação automática de acesso.

### D-011 — PIX somente em modo de teste no MVP

- **Status**: Aceita (provedor pendente — Q-01)
- **Contexto**: regra proíbe pagamentos reais e credenciais fictícias em produção.
- **Decisão**: implementar integração de PIX apenas em sandbox, com webhook validado no servidor e idempotência. Créditos só são liberados após confirmação do provedor. Nenhuma operação financeira real será executada nos testes.
- **Consequências**: depende da escolha do provedor; testes usam *fakes*/assinatura simulada.

### D-012 — Tempo real com Laravel Reverb + Echo

- **Status**: Aceita
- **Contexto**: stack pretendida inclui Reverb e cliente compatível com Pusher.
- **Decisão**: usar Reverb como servidor WebSocket e Laravel Echo com cliente Pusher no frontend; canais **privados** autorizados em `routes/channels.php`.
- **Consequências**: requer Redis/processo Reverb no Compose; histórico persistido continua sendo a fonte de verdade.

### D-013 — Bootstrap 5.3 como framework visual; Tailwind removido

- **Status**: Aceita (implementada no Prompt 05)
- **Contexto**: o esqueleto Laravel 13 traz Tailwind 4; a stack pretendida exige Bootstrap 5.3.
- **Decisão**: adotar Bootstrap 5.3 via Vite/Sass como base visual e **remover** Tailwind do build, evitando dois sistemas de estilos concorrentes. O CSS de identidade (azul-noite, roxo, dourado, marfim) é próprio e documentado em `docs/DESIGN_SYSTEM.md`.
- **Consequências**: `package.json`/`vite.config.js` migrados para Sass; `resources/css/app.css` (Tailwind) substituído por `resources/css/app.scss`; `welcome.blade.php` reescrito com os layouts Blade.

### D-014 — Ambiente de execução reproduzível via Docker (fonte de verdade)

- **Status**: Aceita (implementação no Prompt 02)
- **Contexto**: host sem `pdo_mysql`/`pdo_sqlite`/`redis`; necessidade de paridade.
- **Decisão**: definir Docker Compose como ambiente oficial de desenvolvimento e testes; imagens com versões fixas (`php:8.4-fpm`, `mysql:8.4`, `redis:7`, `nginx` fixado). Testes com banco rodam no container.
- **Consequências**: exige Docker no fluxo; host continua útil para testes unitários sem banco.

### D-015 — PHPUnit (não Pest)

- **Status**: Aceita
- **Contexto**: PHPUnit 12.5 já instalado; Pest não está presente.
- **Decisão**: manter **PHPUnit** e as suítes `tests/Unit` e `tests/Feature` já configuradas em `phpunit.xml`.
- **Consequências**: sem nova dependência; estilo de teste segue o esqueleto atual.

### D-016 — Locale e fuso do produto

- **Status**: Proposta (confirmar — Q-07)
- **Contexto**: configuração atual `UTC`/`en`; produto em português.
- **Decisão proposta**: `APP_LOCALE=pt_BR`, `APP_FALLBACK_LOCALE=en`, `APP_TIMEZONE=America/Sao_Paulo`, mantendo timestamps em UTC no banco quando aplicável.
- **Consequências**: datas e valores exibidos corretamente; atenção a cálculos de cobrança entre fusos.

### D-017 — Design system próprio sobre Bootstrap + componentes Blade

- **Status**: Aceita (Prompt 05)
- **Contexto**: as telas de negócio precisam de consistência visual e acessibilidade sem introduzir um framework de componentes JavaScript.
- **Decisão**: usar tokens Sass + custom properties (`resources/css/`), layouts como componentes Blade anônimos (`x-layouts.public`, `x-layouts.guest`, `x-layouts.panel`, `x-layouts.error`) e componentes reutilizáveis (`x-ui.*`) para botões, cards, alertas, badges/status, campos e estados vazios. Estados de presença sempre exibem texto além da cor e animações respeitam `prefers-reduced-motion`.
- **Consequências**: telas novas devem reutilizar os componentes e layouts; a paleta e o catálogo ficam em `docs/DESIGN_SYSTEM.md`.

---

## 2. Dúvidas que precisam de confirmação

> Estas decisões **não** serão inventadas. Quando não bloquearem a etapa atual, será adotada a opção mais simples e segura, registrada aqui.

| ID | Dúvida | Por que importa | Bloqueia |
|---|---|---|---|
| **Q-01** | Qual provedor de PIX será usado em sandbox (ex.: Asaas, Efí/Gerencianet, Mercado Pago, outro)? | Define SDK, formato de webhook e assinatura | Prompt 14 |
| **Q-02** | Qual o valor de **1 crédito** (`credit_unit_price_cents`) e a **tarifa por minuto** (`rate_credits_per_minute`)? | Base da conversão crédito→dinheiro e da cobrança | Prompts 13/15 |
| **Q-03** | Confirmar arredondamento: minuto iniciado, mínimo de 1 minuto, tolerância/grace period? | Disputas de cobrança e testes | Prompt 15 |
| **Q-04** | Comissão em **basis points** (0–10000) ou `decimal(5,2)`? Alguma taxa padrão? | Precisão e cálculo | Prompt 16 |
| **Q-05** | Atendente pode ficar `online` sem aceitar automaticamente? Há tempo limite para aceite? | Fluxo de solicitação/expiração | Prompt 10 |
| **Q-06** | Administrador pode ler conversas? Em quais hipóteses e com qual auditoria? | Privacidade/LGPD | Prompts 17/18 |
| **Q-07** | Confirmar `America/Sao_Paulo` e `pt_BR`? | Datas, cobrança e UX | Prompt 01 |
| **Q-08** | Formatos/tamanho máximo de imagem de carta; necessidade de redimensionamento (`gd`) e verificação de mime? | Upload e segurança | Prompt 09 |
| **Q-09** | Cliente precisa confirmar e-mail antes de consultar? | Fluxo de cadastro | Prompt 04 |
| **Q-10** | Quais dados mínimos no cadastro do cliente (nome, e-mail, telefone, data de nascimento)? | LGPD/minimização | Prompt 04 |
| **Q-11** | Um usuário pode ter mais de um papel (ex.: admin também atendente)? | Modelo de autorização | Prompt 04 |
| **Q-12** | Cliente escolhe um atendente específico ou entra em fila? | Fluxo de consulta | Prompt 10 |
| **Q-13** | Condições de encerramento automático: inatividade, tempo máximo, saldo, desconexão? | Cobrança e segurança | Prompt 15 |
| **Q-14** | Comissão incide sobre valor bruto oficial ou líquido de taxas do provedor? | Valor da comissão | Prompt 16 |
| **Q-15** | Política de cancelamento/estorno de consulta e de recarga? | Financeiro/auditoria | Prompts 14/15 |
| **Q-16** | Haverá e-mail transacional (recuperação de senha, notificações)? Qual provedor? | Configuração de mail | Prompt 04+ |

---

## 3. Premissas adotadas nesta etapa

1. O repositório é um esqueleto Laravel 13 **sem código de domínio**; nada será reescrito sem necessidade.
2. O ambiente oficial passa a ser **Docker** (Prompt 02); o host atual não é suficiente para testes com banco.
3. Não haverá integração de pagamento real; todo PIX é **sandbox**.
4. Percentuais de comissão e valores de crédito são **configuráveis pelo admin**; nenhum valor de negócio é fixado neste plano.
5. O histórico de chat e as imagens enviadas são **fonte de verdade persistida**, não o WebSocket.
6. Aprovação de candidatura é independente da criação de acesso de atendente.
7. Testes não serão removidos/enfraquecidos; relatórios de teste serão sempre reais.

---

## 4. Histórico de revisões

| Data | Etapa | Alteração |
|---|---|---|
| 2026-10-20 | Prompt 00 | Criação do documento com decisões D-001–D-016 e dúvidas Q-01–Q-16 |
| 2026-10-20 | Prompt 05 | D-013 confirmada/implementada; D-017 (design system e componentes Blade) adicionada |

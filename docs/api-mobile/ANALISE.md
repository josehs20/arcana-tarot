# Análise para API mobile (`/api/v1`) e camada compartilhada

Auditoria produzida na etapa **00** dos prompts `arcana-tarot-prompts/laravel-api` e
atualizada nas etapas **01**, **02** e **03**. Todo o conteúdo abaixo foi verificado no
código do repositório — não em suposições.

## 1. Objetivo

Preparar o Laravel atual (site e painéis em Blade) para também servir uma API REST
versionada em `/api/v1`, consumida por futuros apps Flutter de **cliente** e
**atendente**, sem:

- alterar telas, layout ou identidade visual;
- reescrever autenticação, banco ou stack;
- duplicar cobrança, ledger de créditos, comissões ou ciclo de vida da consulta;
- exigir Redis, worker, Docker, Reverb ou qualquer serviço extra para a API básica
  funcionar na Hostoo.

O admin continua exclusivamente web nesta etapa.

## 2. Stack verificada

| Item | Valor real |
|---|---|
| PHP exigido | `^8.4` (`composer.json`) — runtime local em 8.5.4, código deve seguir compatível com 8.4 |
| Laravel | 13.40.0 |
| PHPUnit | 12.5.43 |
| Pint | 1.32.1 |
| Laravel Socialite | 5.31.0 |
| Laravel Reverb | 1.12.0 (instalado, opcional por ambiente) |
| Intervention Image | 3.11.10 |
| Sanctum | **instalado na etapa 02**: `laravel/sanctum` 4.4.0, `HasApiTokens` no `User`, `config/sanctum.php` e migration `personal_access_tokens`; emissão/revogação usada na etapa 03 |
| Firebase JWT | `firebase/php-jwt` 7.2.1, dependência transitiva do Socialite; valida o ID token Google da API (JWKS/RS256) sem pacote novo |
| Filas / cache / sessão em produção | `database` (Hostoo, conforme `docs/ARQUITETURA_PRODUCAO_HOSTOO.md`) |
| Broadcast em produção | `log` na Hostoo; `reverb` no `.env.example` local |

## 3. Inventário HTTP e autenticação

- `php artisan route:list --json`: **163 rotas** — 156 web e 7 em `api/v1`
  (login, cadastro, Google, `me`, logout, reenvio de confirmação e o alias
  `GET /api/v1/me`).
- `bootstrap/app.php` registra `web`, `api`, `commands`, `channels` e `health`;
  o grupo `api` aplica `throttle:api` e `SubstituteBindings`.
  Já existe `shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson())`,
  ou seja, erros em `/api/*` já saem em JSON.
- Existem `routes/api.php`, `app/Http/Resources/UserResource.php`, `config/sanctum.php`
  e `config/api.php`; `config/cors.php` continua ausente de propósito (app nativo
  não usa CORS e o AJAX web será same-origin). A rota SPA `/sanctum/csrf-cookie`
  está desativada (`sanctum.routes = false`), pois o app só usa Bearer token.
- Autenticação web é **somente sessão** e continua intocada:
  - e-mail/senha (`AuthenticatedSessionController` + `LoginRequest`);
  - Google via Socialite (`GoogleAuthenticationController`);
  - confirmação de e-mail (`EmailVerificationController`, middleware `verified`);
  - redefinição de senha (`PasswordResetLinkController`, `NewPasswordController`);
  - logout invalida a sessão e dispara efeitos de disponibilidade/ciclo de vida.
- A API em `/api/v1/auth` usa **somente Bearer token** (`auth:sanctum`):
  - login/cadastro por e-mail e senha, Google por ID token e logout com
    revogação do token atual (etapa 03);
  - cada token tem apenas a ability `client` ou `attendant`; admin recebe 403;
  - `GET /api/v1/auth/me` devolve `UserResource`; `GET /api/v1/me` permanece
    como alias da etapa 02;
  - o cookie/sessão web não autentica `/api/*` (`sanctum.guard = []`).
- Autorização por Policies/Gates (`ConsultationPolicy`, `PaymentPolicy`, `WalletPolicy`,
  `access-client-panel`, `access-attendant-panel`, `role:*` via `EnsureUserRole`).
- Throttles existentes nas rotas sensíveis (`throttle:5,10`, `throttle:6,1`,
  `throttle:credit-purchases`, etc.) — servem de referência para os limites da API.
- Testes: 61 arquivos Feature + 8 Unit; o PHPUnit executa **702 casos**
  (data providers) com cerca de 7,7 mil asserções.

## 4. Lógica de negócio já isolada (reaproveitável pela API)

Estas classes em `app/Support` já concentram regras e não dependem de sessão,
redirect ou view. A API deve chamá-las em vez de recriar regra:

| Classe | Responsabilidade |
|---|---|
| `ConsultationLifecycleManager` | solicitar, aceitar, recusar, cancelar, encerrar, expirar, heartbeat/estado, `endActiveForLogout` |
| `ConsultationMessageManager` | envio de texto, cartas e voz com transação, idempotência de cards e guarda de privacidade |
| `ConsultationBillingManager` / `CommissionManager` | cobrança por minuto, snapshots de preço/taxa, accrual e estorno de comissão |
| `CreditWalletManager` | carteira, crédito, débito, ajuste e razão imutável (fonte única do saldo) |
| `PaymentManager` / `InfinitePayCheckoutManager` | recarga Pix, checkout InfinitePay, reconciliação e confirmação server-side |
| `AttendantAvailabilityManager` | presença online/ocupado/offline com auditoria |
| `AttendantAccountManager` / `GoogleAccountManager` | criação de atendente e resolução de conta Google |
| `ConsultationModalityCatalog` / `ConsultationPricing` / `PaymentLimits` / `CreditPackages` | modalidades, preços, mínimos/máximos e pacotes |
| `ConsultationActivityTracker` / `ConsultationCallManager` | presença e sinalização WebRTC |
| `FirstAccessBonus` | bônus de primeiro acesso (somente após verificação de e-mail) |
| Presenters (`ConsultationStatePresenter`, `ConsultationMessagePresenter`, etc.) | serialização de estado/mensagem para JSON — **com ressalva**: usam `route()` web e precisam de Resources próprios na API |

Managers não usam `Gate`/`Auth`; autorização permanece nos controllers (web ou API).
Essa separação deve ser mantida na camada compartilhada.

## 5. Acoplamentos HTTP encontrados e tratamento

| Fluxo | Arquivo atual | O que estava acoplado | Etapa 01 |
|---|---|---|---|
| Cadastro de cliente | `Auth/RegisteredUserController` | transação + criação do usuário + carteira dentro do controller | `ClientAccountManager::register()` |
| Efeitos de login/logout | `Auth/AuthenticatedSessionController`, `Auth/GoogleAuthenticationController` | `signIn`, `endActiveForLogout` e `signOut` repetidos nos controllers, com `report()` | `AuthenticationLifecycleManager::afterLogin()/afterLogout()` |
| Diretório público | `AttendantDirectoryController` | select público, `withExists`, `published()` e ordenação inline | `AttendantDirectoryQuery::query()/findPublishedOrFail()` |
| Histórico do chat | `ConsultationController::chatData()` / `availableCardDecks()` | paginação, inversão cronológica, contador de novas, marcação de leitura e baralhos | `ConsultationMessageFeed` |
| Painel / pendentes | `PanelEventsController`, `Attendant/ConsultationController::pending()` | consulta duplicada de consulta aberta e fila de solicitações | `ConsultationInbox` |
| Recarga Pix / checkout | `Client/CreditPurchaseController`, `Client/CreditCheckoutController` | apenas mapeamento de exceção de domínio → `ValidationException`/flash e URL de retorno em sessão | **sem extração**: managers já recebem identidade/valores validados; a API fará seu próprio mapeamento HTTP |
| Envio de mensagem/carta/voz | `ConsultationMessageController` | gate + manager; `index` já expõe JSON incremental por `after_id` | **sem extração**: já é reutilizável |
| Listagens/histórico dos painéis | `Client/HistoryController`, `Attendant/HistoryController`, `DashboardController` | filtros de `Request` + paginação + views | não extraído nesta etapa; API pode consultar as relations `clientConsultations()`/`attendantConsultations()` e evoluir depois se houver duplicação real |
| Presenters de estado | `app/Support/*Presenter.php` | `route()` gera URLs dependentes de sessão | API terá `Http/Resources` próprios na etapa 02/04 |

## 6. Lacunas específicas para a API

1. **Sanctum resolvido na etapa 02**: guard `sanctum`, tabela `personal_access_tokens`,
   `HasApiTokens` no `User` e `config/sanctum.php`; o guard aceita apenas Bearer token.
   A etapa 03 emite os tokens (`ApiTokenIssuer`) e revoga o atual no logout.
2. **Rotas de autenticação criadas na etapa 03**: `routes/api.php` tem
   `/api/v1/auth/login`, `/register`, `/google`, `/me`, `/logout` e
   `/email/verification-notification`; o login/cadastro/Google usam
   `throttle:api-auth` (10/min por IP) e o login mantém 5 tentativas por
   e-mail/IP. O endpoint `GET /api/v1/me` da etapa 02 permanece como alias.
3. **`app/Http/Resources`**: `UserResource` (etapa 02) e `AuthTokenResource`
   (etapa 03, token + metadados + usuário). Os demais payloads entram nas etapas
   04/05 no mesmo padrão, sem `route()` de sessão.
4. **Verificação de telefone não existe**: `phone_verified_at` permanece, mas o fluxo
   e a tabela `phone_verifications` foram removidos na migration
   `2026_11_10_141545_drop_phone_verifications_table.php`. A API não bloqueia por
   telefone; qualquer requisito de SMS continua pendente de decisão de produto (P-03).
5. **Google mobile resolvido na etapa 03**: `GoogleIdTokenVerifier` valida o ID
   token do SDK no servidor (assinatura RS256 contra o JWKS do Google, `iss`,
   `aud`, `sub`, `email_verified`) e `GoogleAccountManager::resolve` mantém o
   vínculo seguro por `google_id` e as regras de conta existente/inativa. O fluxo
   web por Socialite não foi alterado.
6. **Retorno de recarga**: o web guarda `credits.return_consultation` em sessão. O app
   não tem sessão; o contrato da API deve devolver `checkout_url`/status e o app abre
   o navegador externo. Nenhum crédito pode depender do retorno do app.
7. **Realtime**: Hostoo roda `BROADCAST_CONNECTION=log`; sem Reverb o app precisa do
   fallback de leitura (polling pontual / `after_id`), como o painel web já faz
   (`PanelEventsController`, `RealtimeMode::current()`).
8. **CORS resolvido para esta fase**: não há `config/cors.php` e não deve haver
   enquanto o app nativo for o único consumidor; o AJAX do site (etapa 07) usa as
   rotas web same-origin. Frontend externo futuro exige origens explícitas, nunca `*`.
9. **Idempotência financeira**: `PaymentManager` e `InfinitePayCheckoutManager` já
   exigem `operation_id`/reference; a API deve continuar enviando chave idempotente e
   nunca aceitar preço/valor calculado pelo cliente.
10. **Privacidade**: `ConsultationPolicy` nega chat ao admin; a API deve reutilizar as
    mesmas Gates e nunca expor `commission_rate_bps`, e-mail, telefone, ledger de outro
    usuário ou caminhos privados de arquivo.

## 7. Riscos e restrições

- **Regressão web**: os 702 casos (~7,7 mil asserções) são a rede de segurança. Cada
  extração deve manter respostas, flashes, redirects, eventos, auditoria e
  transações idênticos.
- **PHP 8.4**: não usar sintaxe exclusiva de 8.5, apesar do runtime local.
- **Dinheiro**: nada de segundo ledger, recálculo de preço no cliente ou liberação de
  saldo sem confirmação server-side do provedor.
- **Hostoo**: sem Redis/worker/Reverb obrigatórios; sessão/cache/fila em `database`,
  broadcast em `log` por padrão.
- **Dados públicos**: diretório deve continuar selecionando apenas colunas públicas.

## 8. Plano incremental

| Etapa | Entrega | Status |
|---|---|---|
| 00 | Auditoria e plano (`ANALISE.md`, `STATUS.md`) | concluída |
| 01 | Camada compartilhada sem regressão | concluída |
| 02 | API v1, Sanctum, respostas/erros padronizados | concluída |
| 03 | Login, cadastro, tokens e Google web/mobile | concluída |
| 04 | APIs de leitura (perfil, atendentes, modalidades, baralhos/cartas, consultas, créditos) | pendente |
| 05 | APIs de escrita (consultas, mensagens, cartas, operações financeiras) | pendente |
| 06 | Reverb/canais privados e fallback sem Reverb | pendente |
| 07 | AJAX do site usando a mesma camada de aplicação | pendente |
| 08 | Testes finais, documentação de endpoints e checklist Hostoo | pendente |

## 9. Decisões pendentes

| ID | Decisão | Recomendação |
|---|---|---|
| P-01 | Instalar `laravel/sanctum` | **resolvida na etapa 02**: 4.4.0 instalado, compatível com Laravel 13 |
| P-02 | Como validar ID token Google no mobile | **resolvida na etapa 03**: `firebase/php-jwt` 7.2.1 (já transitivo via Socialite) valida RS256 contra o JWKS do Google, com cache de 1 h e nova busca em rotação de `kid`; `iss`/`aud`/`sub`/`email_verified` conferidos no servidor |
| P-03 | Verificação de telefone | **em aberto**: confirmar se é requisito de produto; o fluxo não existe e a API não bloqueia por `phone_verified_at` |
| P-04 | Payloads da API | **parcial**: `UserResource` criado; criar os demais Resources sem reutilizar presenters com `route()` web |
| P-05 | Mídia privada (cartas, áudio) | expor via endpoints autenticados/URLs assinadas; nunca tornar o disco privado público |
| P-06 | Tempo real na Hostoo | manter fallback por polling/`after_id`; Reverb apenas quando provisionado |
| P-07 | Pagamento no app | abrir `checkout_url` em navegador externo (sem compra in-app nesta fase) |
| P-08 | CORS | **resolvida na etapa 02**: sem CORS agora; app nativo não exige e o AJAX web será same-origin |

## 10. Critérios de aceite da etapa 01

- Controllers web continuam com as mesmas respostas e efeitos colaterais.
- Nenhuma rota, tela ou endpoint novo.
- Serviços recebem identidade e dados validados explicitamente; não leem sessão.
- Transações, eventos, auditoria, cobrança, ledger e comissões preservados.
- Testes existentes passando + testes novos cobrindo as regras extraídas.

## 11. Critérios de aceite da etapa 02

- `/api/v1/me` responde 200 com identidade e 401 sem token válido.
- Cookie de sessão web não autentica `/api/*`; login e painéis Blade seguem intactos.
- Token expirado ou revogado é rejeitado; `expires_at` é gravado e há limpeza agendada.
- Erros JSON têm `message` (e `errors` na validação); 404 de rota de API também é JSON.
- Limite por token/IP ativo; nenhuma dependência de Redis/worker/Docker para a API.
- Nenhuma alteração em `routes/web.php`, `config/auth.php`, migrations existentes ou CSRF.

## 12. Critérios de aceite da etapa 03

- `POST /api/v1/auth/login` e `/register` devolvem Bearer token com apenas
  `client` ou `attendant`; admin e conta inativa não recebem token.
- Login inválido responde 422 com mensagem genérica; cadastro reutiliza as regras
  do formulário web e cria a carteira na mesma transação.
- Logout revoga somente o token atual (204) e os demais tokens continuam válidos.
- Google mobile valida o ID token no servidor (assinatura, `iss`, `aud`,
  `email_verified`), vincula por `google_id` sem duplicar conta e não aceita
  e-mail/nome enviados pelo corpo; sem client ID responde 503.
- Reenvio de confirmação respeita o limite de 6/min e não envia nada para e-mail
  já verificado.
- Sessão web, login Google por Socialite e painéis Blade permanecem inalterados;
  nenhum token do Google é armazenado como sessão da API.
- Cobertura automatizada dos caminhos de sucesso, falha, conta existente,
  vínculo seguro, logout, rate limit e bloqueios.

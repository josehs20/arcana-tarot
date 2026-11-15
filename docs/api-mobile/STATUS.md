# STATUS — preparação da API mobile

> Atualizado em 13/11/2026. Contexto persistente das etapas
> `arcana-tarot-prompts/laravel-api`. Não substitui `ANALISE.md`: aqui ficam
> apenas o andamento, as decisões e o que já pode ser reutilizado.

## 1. Resumo

- Auditoria da etapa 00 concluída; a etapa 02 instalou a fundação da API
  (`laravel/sanctum` 4.4.0, `routes/api.php`, `config/sanctum.php`) e o endpoint
  `GET /api/v1/me`.
- A etapa 03 adicionou login/cadastro por e-mail e senha, login com Google por
  ID token, logout com revogação imediata e reenvio do link de confirmação em
  `/api/v1/auth`.
- Site e painéis continuam **somente web/sessão**; as rotas, telas e cookies do
  Blade não foram alterados. A API não cria sessão nem aceita cookie.
- Dependência nova: apenas `laravel/sanctum` (oficial, exigida pela API móvel).
  A validação do ID token usa `firebase/php-jwt` 7.2.1, que já vem como
  dependência transitiva do Socialite — não foi instalado pacote adicional.
  Nenhum serviço extra (Redis, Docker, worker, Reverb) é necessário para a API.

## 2. Andamento

| Etapa | Entrega | Status |
|---|---|---|
| 00 | Auditoria e plano | **concluída** |
| 01 | Serviços compartilhados | **concluída** |
| 02 | API v1 + Sanctum | **concluída** |
| 03 | Auth/tokens/Google | **concluída** — P-03 (telefone) segue como decisão de produto |
| 04 | APIs de leitura | pendente |
| 05 | APIs de escrita | pendente |
| 06 | Tempo real | pendente — depende de P-06 |
| 07 | AJAX do site | pendente |
| 08 | Testes/documentação Hostoo | pendente |

## 3. Decisões pendentes

| ID | Assunto | Situação |
|---|---|---|
| P-01 | Instalar `laravel/sanctum` | **resolvida**: Sanctum 4.4.0 instalado na etapa 02 |
| P-02 | Validar ID token Google no mobile | **resolvida na etapa 03**: assinatura RS256 conferida localmente contra o JWKS do Google com `firebase/php-jwt`; `iss`, `aud`, `sub` e `email_verified` validados no servidor |
| P-03 | Verificação de telefone | **em aberto**: o fluxo não existe no código; a API não bloqueia por telefone até definição de produto |
| P-04 | Resources próprios para a API | **parcial**: `UserResource` criado; demais payloads nas etapas 04/05 |
| P-05 | Mídia privada (cartas/áudio) | definir endpoint autenticado/URL assinada |
| P-06 | Reverb x polling na Hostoo | manter fallback; Reverb opcional |
| P-07 | Checkout no app | navegador externo para `checkout_url` |
| P-08 | CORS | **resolvida na etapa 02**: sem CORS; app nativo não usa e o AJAX web será same-origin |

Detalhes e recomendações em `docs/api-mobile/ANALISE.md` (§9).

## 4. Serviços compartilhados criados na etapa 01

| Classe | Métodos | Usuários atuais | Uso previsto pela API |
|---|---|---|---|
| `App\Support\ClientAccountManager` | `register(array $attributes): User` | `Auth/RegisteredUserController` | cadastro de cliente (etapa 03) |
| `App\Support\AuthenticationLifecycleManager` | `afterLogin(User)`, `afterLogout(User)` | `AuthenticatedSessionController`, `GoogleAuthenticationController` | login/logout por token (etapas 03/05) |
| `App\Support\AttendantDirectoryQuery` | `query(): Builder`, `findPublishedOrFail(int)` | `AttendantDirectoryController` | diretório público (etapa 04) |
| `App\Support\ConsultationMessageFeed` | `paginate()`, `unreadCount()`, `markRead()`, `availableCardDecks()` | `ConsultationController` (telas `show` e `workspace`) | histórico/leitura do chat (etapas 04/05) |
| `App\Support\ConsultationInbox` | `openFor(User)`, `pendingForAttendant(User)` | `PanelEventsController`, `Attendant/ConsultationController::pending()` | painel/consulta aberta/pendentes (etapas 04/06) |

Nenhum desses serviços lê sessão, `Request`, `Gate` ou `Auth`. Identidade e dados
validados entram por parâmetro; autorização continua no controller.

## 5. Casos de uso que a API pode reutilizar

Já isolados antes da etapa 01 (sem alteração de contrato):

- **Consultas**: `ConsultationLifecycleManager` (`request`, `accept`, `reject`,
  `cancel`, `end`, `interruptIfInactive`, `closeIfOutOfCredits`,
  `endActiveForLogout`, `expire`).
- **Mensagens/cartas/voz**: `ConsultationMessageManager` (`sendText`, `sendCards`,
  `sendVoice`), `ConsultationCardImageStorage`, `ConsultationVoiceStorage`.
- **Cobrança/comissão**: `ConsultationBillingManager`, `CommissionManager`,
  `CommissionCalculator`, `ConsultationPricing`.
- **Créditos**: `CreditWalletManager` (`walletFor`, `credit`, `debit`, `adjust`).
- **Pagamentos**: `PaymentManager::startRecharge`, `InfinitePayCheckoutManager`
  (`startCheckout`, `reconcile`), `PaymentLimits`, `CreditPackages`.
- **Presença/chamada**: `AttendantAvailabilityManager`, `ConsultationActivityTracker`,
  `ConsultationCallManager`, `ConsultationSafety`.
- **Catálogo**: `ConsultationModalityCatalog`, `TarotDeck`/`TarotCard` (somente leitura).
- **Contas**: `GoogleAccountManager::resolve`, `FirstAccessBonus`, `UserAccessManager`.
- **Serialização**: presenters em `app/Support` servem de referência de campos, mas a
  API deve criar `Http/Resources` sem `route()` de sessão.

## 6. Ambiente e comandos

- PHP exigido `^8.4` (runtime local 8.5.4), Laravel 13.40.0, PHPUnit 12.5.43.
- Banco de testes: SQLite em memória (`phpunit.xml`).
- Testes: `php artisan test --compact` (suíte completa) ou
  `php artisan test --compact tests/Feature/<Arquivo>.php`.
- Estilo: `vendor/bin/pint --dirty --format agent`.
- O PHP do host **não possui** `pdo_sqlite`, `pdo_mysql` nem GD, então a suíte foi
  executada em um container descartável `arcana-php-test:8.5`
  (`php:8.5-cli-alpine` + GD com WebP), montando o repositório em `/app`:
  `docker run --rm --user "$(id -u):$(id -g)" -v "$PWD":/app -w /app arcana-php-test:8.5 php artisan test --compact`.
  Em CI/Hostoo com as extensões normais, o comando é o usual.
- Não rodar `config:cache`/`route:cache` com banco local durante as etapas; a Hostoo
  usa `SESSION_DRIVER`, `CACHE_STORE` e `QUEUE_CONNECTION` em `database` e
  `BROADCAST_CONNECTION=log`.

## 7. Validação da etapa 01

| Verificação | Resultado |
|---|---|
| Suíte completa (`php artisan test --compact`) | **662 passaram / 2 falharam** (664 testes, 7.525 asserções) |
| Testes novos das regras extraídas | **14 passaram** (44 asserções) |
| Regressão dirigida (auth, Google, e-mail, diretório, chat, workspace, realtime, polling, logout) | **164 passaram** |
| `vendor/bin/pint --format agent` nos arquivos alterados | **passed** |

As 2 falhas da suíte completa são **pré-existentes e ambientais**, reproduzidas
também no commit `HEAD` sem estas mudanças:

1. `AdminEmailCampaignTest::test_repeated_and_invalid_addresses_are_ignored`
   (`tests/Feature/AdminEmailCampaignTest.php:137`) — o dispatcher ordena por
   `name` antes de deduplicar e-mails; com nomes aleatórios do Faker, a conta que
   sobrevive varia. Rodei o teste isolado 5 vezes: 3 passes e 2 falhas.
2. `MvpIntegratedFlowTest` (linha 210) — espera `Content-Type: image/png` no
   snapshot, mas o ambiente com GD/WebP converte a imagem. Também falha no `HEAD`
   com a mesma imagem de container.

## 8. Fundação da API (etapa 02)

### Instalação

- `laravel/sanctum` **4.4.0** (`composer.json` em `^4.0`), compatível com Laravel
  13.40 e PHP 8.4+.
- Instalado com `php artisan install:api --no-interaction --without-migration-prompt`.
- Migration `database/migrations/2026_11_13_201220_create_personal_access_tokens_table.php`
  (`personal_access_tokens`: token em SHA-256, abilities, `last_used_at`, `expires_at`).
- `config/sanctum.php` publicado e ajustado (`routes => false` desativa o endpoint
  SPA `/sanctum/csrf-cookie`); `config/api.php` criado para os limites.
- `App\Models\User` usa `Laravel\Sanctum\HasApiTokens`.
- `bootstrap/app.php`: `api: routes/api.php` e `$middleware->throttleApi('api')`.
  Nenhuma mudança em `routes/web.php`, sessão, CSRF ou middleware `verified`.

### Autenticação e endpoint inicial

- `GET /api/v1/me` (`api.v1.me`) exige `auth:sanctum` e devolve
  `App\Http\Resources\UserResource` com `id`, `name`, `email`, `phone`,
  `birth_date`, `gender`, `role`, `is_active`, `email_verified_at`,
  `phone_verified_at`, `profile_complete` e `created_at`.
- `/api/*` aceita **somente Bearer token**: `sanctum.guard = []` de propósito.
  Cookie de sessão web não autentica a API (evita CSRF e mistura de canais); a
  sessão `web` continua funcionando normalmente nas rotas Blade.
- Usuário inativo recebe `403 {"message":"Conta inativa."}`.
- Abilities: na emissão (etapa 03) cada token recebe apenas `client` ou
  `attendant`, conforme o papel; `/me` não exige ability específica e as rotas
  seguintes combinam `ability:*` com Policies/Gates. O admin não usa o app.
- Token expira em `SANCTUM_TOKEN_EXPIRATION` (padrão 43.200 min = 30 dias). O
  `expires_at` é gravado na criação (model event em `AppServiceProvider`) e o
  guard também valida `created_at` + config, garantindo defesa em profundidade.
- Revogação imediata por `$token->delete()` (logout da etapa 03).
  `sanctum:prune-expired --hours=24` roda diariamente via `routes/console.php`;
  na Hostoo o cron já necessário para `schedule:run` executa a limpeza.

### Contrato JSON

| Situação | HTTP | Corpo |
|---|---|---|
| Sucesso | 200 | `{"data": {...}}` (Resource) |
| Validação | 422 | `{"message": "...", "errors": {"campo": ["..."]}}` |
| Não autenticado | 401 | `{"message": "Unauthenticated."}` |
| Proibido | 403 | `{"message": "..."}` |
| Não encontrado | 404 | `{"message": "..."}` |

`bootstrap/app.php` já renderiza JSON para `api/*`. O formato acima é o padrão do
Laravel e será mantido nas etapas 03–05. Com `APP_DEBUG=true` o Laravel acrescenta
`exception`/`trace`; em produção (`APP_DEBUG=false`) ficam apenas os campos acima.

### Limites e CORS

- `throttle:api` em todo o grupo `api`: `API_RATE_LIMIT_PER_MINUTE` (padrão 60/min)
  por usuário do token, caindo para IP quando não há token válido.
- `api-auth` (10/min por IP) já registrado para login/cadastro da etapa 03.
- **Sem `config/cors.php`**: app nativo não usa CORS e o AJAX do site (etapa 07)
  será same-origin nas rotas web. Se surgir frontend web externo, configurar
  apenas origens explícitas — nunca `*`.

### Instalação/rollback na Hostoo

- Deploy: `composer install --no-dev --optimize-autoloader` e
  `php artisan migrate --force`.
- Sem Docker, Redis, worker ou Reverb para a API básica; cache, sessão e fila
  continuam em `database`.
- Rollback seguro (remove a fundação e revoga todos os tokens):
  1. `php artisan migrate:rollback --step=1` (remove `personal_access_tokens`);
  2. remover `routes/api.php`, a linha `api:` de `bootstrap/app.php`,
     `config/sanctum.php` e `config/api.php`;
  3. `composer remove laravel/sanctum` e `php artisan optimize:clear`.
  Para apenas invalidar os tokens, apagar as linhas de `personal_access_tokens`
  (ou usar o logout da etapa 03), sem reverter a migration.

## 9. Validação da etapa 02

| Verificação | Resultado |
|---|---|
| Testes novos (`tests/Feature/ApiFoundationTest.php`) | **10 passaram** (39 asserções): token válido/inválido, hash/revogação/expiração, limpeza agendada, inativo, sessão web isolada, limite 429 e 404 JSON |
| Suíte completa (`php artisan test --compact`) | **672 passaram / 2 falharam** (674 testes, 7.564 asserções nesta execução) |
| `vendor/bin/pint --dirty --format agent` | **passed** |
| `php artisan schedule:list` | 5 tarefas, incluindo `sanctum:prune-expired --hours=24` |

As duas falhas são **pré-existentes e ambientais**, já registradas na etapa 01:
`MvpIntegratedFlowTest:210` espera `image/png` e o container com GD/WebP entrega
`image/webp`; `AdminEmailCampaignTest::test_repeated_and_invalid_addresses_are_ignored`
é flaky porque o dispatcher ordena por `name` antes de deduplicar e-mails (nesta
rodada, 2 de 3 execuções isoladas falharam). Nenhuma falha nova da API.

## 10. Autenticação da API (etapa 03)

### Endpoints

| Método | Rota | Autenticação | Limite | Sucesso |
|---|---|---|---|---|
| POST | `/api/v1/auth/login` | pública | `api-auth` + 5/60 s por e-mail/IP | 200 com token |
| POST | `/api/v1/auth/register` | pública | `api-auth` | 201 com token |
| POST | `/api/v1/auth/google` | pública | `api-auth` | 200 com token |
| GET | `/api/v1/auth/me` | `auth:sanctum` | `throttle:api` | 200 com `UserResource` |
| POST | `/api/v1/auth/logout` | `auth:sanctum` | `throttle:api` | 204 sem corpo |
| POST | `/api/v1/auth/email/verification-notification` | `auth:sanctum` | `throttle:6,1` | 200 com mensagem |
| GET | `/api/v1/me` | `auth:sanctum` | `throttle:api` | alias da etapa 02, mantido por compatibilidade |

`POST /auth/register` reutiliza integralmente `App\Http\Requests\Auth\RegisterRequest`
(domínios permitidos, telefone, idade, gênero e confirmação de senha) e apenas
acrescenta `device_name`. O login usa `LoginRequest` próprio: não chama
`Auth::attempt`, não cria sessão e aplica o mesmo limite de 5 tentativas por
e-mail/IP do formulário web, além do `throttle:api-auth` por IP (10/min).

### Resposta de token

```json
{
  "data": {
    "token": "1|texto-em-claro-exibido-uma-unica-vez",
    "token_type": "Bearer",
    "abilities": ["client"],
    "expires_at": "2026-12-13T21:35:00+00:00",
    "user": { "id": 1, "name": "...", "email": "...", "role": "client" }
  }
}
```

- Cada token recebe **somente** `client` ou `attendant`, derivado do papel em
  `ApiTokenIssuer`; o admin recebe 403 e nenhum token é criado.
- `device_name` é opcional (máx. 100); vazio usa `mobile`.
- O token em claro só aparece nessa resposta; o banco guarda SHA-256.
- `AuthTokenResource` serializa o `UserResource`, sem senha, `remember_token` ou
  qualquer segredo.

### E-mail e senha

- Login inválido, senha errada e conta inativa devolvem a **mesma** mensagem
  genérica (`auth.failed`) em 422, sem revelar se o e-mail existe.
- Cadastro cria conta + carteira na mesma transação (`ClientAccountManager`) e
  já devolve token, mas o e-mail nasce **não confirmado**. O app pode consultar
  `/auth/me` e pedir o reenvio; o bônus de primeiro acesso continua sendo
  concedido apenas na confirmação do e-mail, como no fluxo web.
- Falha de SMTP durante o cadastro é reportada e não desfaz a conta; o reenvio
  fica disponível em `POST /auth/email/verification-notification`.
- Reenvio: 200 com `Enviamos um novo link...`; usuário já verificado recebe
  200 com `Seu e-mail já está confirmado.` e nenhum e-mail. Falha de envio
  vira 503 controlado.
- **P-03 continua em aberto**: não existe verificação de telefone no código e a
  API não bloqueia login/cadastro por `phone_verified_at`.

### Google mobile

- Configuração: `GOOGLE_LOGIN_ENABLED=true` e ao menos um client ID em
  `GOOGLE_CLIENT_ID` ou `GOOGLE_MOBILE_CLIENT_IDS` (lista separada por vírgula).
  O client web entra automaticamente na audiência aceita porque o Android pode
  usá-lo como `serverClientId`. Sem nenhum ID, a rota responde 503.
- O `client_secret` **não** é usado no fluxo mobile; o fluxo web por sessão
  continua exigindo as duas credenciais, sem alteração.
- O app envia somente `id_token` (máx. 4096) e `device_name`. `email`, `name` e
  demais dados do corpo são ignorados: a identidade vem das claims assinadas.
- `GoogleIdTokenVerifier` confere RS256 contra o JWKS oficial
  (`GOOGLE_JWKS_URI`, padrão `https://www.googleapis.com/oauth2/v3/certs`),
  com cache de 1 h, tolerância de relógio de 60 s e uma nova busca do JWKS se o
  `kid` não for encontrado. Depois valida `iss`, `aud`, `sub` e
  `email_verified`. O ID token do Google não é armazenado nem vira token da API.
- `GoogleAccountManager::resolve` mantém as regras do fluxo web: vínculo pelo
  `sub` (`google_id`), e-mail existente só é vinculado se ainda não tiver outro
  `google_id`, conta inativa é bloqueada e conta nova nasce cliente verificado
  com carteira vazia. Conta nova dispara `Registered` e recebe o bônus de
  primeiro acesso; conta recorrente não recebe de novo.

| Situação Google | HTTP | Corpo |
|---|---|---|
| Token inválido, expirado, emissor/audiência errados ou e-mail não verificado | 422 | `errors.id_token` |
| E-mail já vinculado a outro `google_id` | 409 | `message` explicativo |
| Conta desativada | 403 | `message` explicativo |
| Google desligado/sem client ID | 503 | `message` de indisponibilidade |
| JWKS indisponível | 422 | falha fechada, sem criar conta |

## 11. Validação da etapa 03

| Verificação | Resultado |
|---|---|
| Testes novos (`tests/Feature/Api/ApiAuthenticationTest.php` + `GoogleApiAuthenticationTest.php`) | **28 passaram** (137 asserções) |
| Regressão dirigida (API, auth web, e-mail, Google web e serviços compartilhados) | **87 passaram** (434 asserções) |
| Suíte completa (`php artisan test --compact`) | **701 passaram / 1 falhou** (702 testes, 7.702 asserções nesta execução) |
| `vendor/bin/pint --format agent` nos arquivos da etapa | **passed** |
| `php artisan route:list --json` | **163 rotas**, sendo **7** em `api/*` |

As falhas conhecidas da suíte completa são as **mesmas pré-existentes e
ambientais** da etapa 02, sem relação com a API: `MvpIntegratedFlowTest:210`
espera `image/png` e o container com GD/WebP entrega `image/webp` (falhou nesta
execução); `AdminEmailCampaignTest::test_repeated_and_invalid_addresses_are_ignored`
é flaky porque o dispatcher ordena por `name` antes de deduplicar e-mails (falhou
na rodada anterior e passou nesta).

Cobertura dos testes novos: login correto (e-mail normalizado e uso do token),
senha errada, inativo, admin bloqueado, ability de atendente, cadastro com
carteira/link, mesmas regras do formulário web, logout revogando só o token
atual, reenvio para não verificado, resposta para já verificado, rate limit,
Google novo/recorrente/vínculo de e-mail, conflito 409, inativo 403, admin 403,
token sem `email_verified`, audiência errada, assinatura errada, expirado,
emissor inválido, corpo ignorado, JWKS fora do ar, Google sem client ID e bônus
de primeiro acesso.

## 12. Próximo passo

Etapa **04** (`04-leitura.md`): APIs de leitura para perfil, atendentes,
modalidades, baralhos/cartas, consultas e créditos, reutilizando
`AttendantDirectoryQuery`, `ConsultationInbox`, `ConsultationMessageFeed` e os
Resources da API. **P-03** (verificação de telefone) segue em aberto e só deve
bloquear a etapa se o produto confirmar o requisito; o fluxo Google mobile já
está desbloqueado (P-02 resolvida).

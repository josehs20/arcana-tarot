# Autenticação com Google, confirmação por SMS e bônus de primeiro acesso

> Login/cadastro com Google (OAuth 2.0 via Socialite), cadastro por e-mail em
> duas etapas com código SMS (Twilio Verify) e bônus configurável de créditos no
> primeiro acesso. Este documento descreve as garantias, a configuração das
> credenciais, os fluxos, o modelo de dados, os testes automatizados e o roteiro
> de validação manual.

## 1. Escopo e garantias

- O login por **e-mail e senha continua sendo o caminho principal**. Google e
  SMS são alternativas opcionais: sem credenciais válidas, o botão do Google
  não é renderizado e o passo de SMS não entra no cadastro — a aplicação nunca
  mostra uma tela que terminaria em erro de configuração.
- **Nenhum método alternativo cria conta sem identidade verificada**: no Google
  a claim `email_verified` precisa ser verdadeira; no SMS a conta só é criada
  depois do código aprovado.
- **`users.google_id` é o `sub` do Google**, não o e-mail. O mesmo cliente
  continua reconhecido mesmo que troque o endereço na conta Google. A coluna tem
  índice único, então duas contas locais nunca compartilham a mesma identidade.
- **O código SMS é gerado e conferido pelo provedor.** A aplicação guarda apenas
  o estado local (tentativas, validade, dados do cadastro) e nunca persiste o
  código digitado.
- **Limites são aplicados antes de chamar o provedor**: validade (`ttl_minutes`),
  tentativas (`max_attempts`) e intervalo entre envios
  (`resend_cooldown_seconds`) protegem o custo por SMS sem depender do cliente.
- **A verificação pendente é referenciada pela sessão**, não por um id vindo do
  navegador (`authentication.sms.session_key`), o que impede conferir o código
  de outra pessoa.
- **Falha de infraestrutura não derruba o cadastro**: erros do Twilio viram
  mensagem amigável + `report()` no log, mantendo o cliente no fluxo.
- **O bônus é idempotente e nunca bloqueia o acesso**: a chave
  `first_access_bonus:user:{id}` garante uma única concessão e qualquer exceção
  ao conceder é registrada no log, sem impedir o login/cadastro.
- **Nenhum valor de bônus vem do navegador**: o crédito é calculado pela tarifa
  vigente do crédito (`credit_unit_price_cents`).

## 2. Componentes

| Camada | Arquivo | Papel |
|---|---|---|
| Configuração | `config/authentication.php` | Blocos `google`, `sms` e `first_access_bonus` (valores de instalação). |
| Configuração | `config/services.php` | Chaves `google` e `twilio`. |
| Gate de exibição | `app/Support/AuthenticationSettings.php` | `googleEnabled()` (flag + credenciais), `smsEnabled()`, `smsCodeLength()` (4–8, padrão 6). |
| Google | `app/Http/Controllers/Auth/GoogleAuthenticationController.php` | `redirect()` e `callback()`, tratamento de `InvalidStateException` e falhas do provider. |
| Google | `app/Support/GoogleAccountManager.php` | Resolve `sub`/e-mail em conta local (vínculo, criação, bloqueio). |
| Google | `app/Support/GoogleAccountException.php` | Erros de domínio com mensagem pronta para o cliente. |
| SMS | `app/Http/Controllers/Auth/PhoneVerificationController.php` | `show()`, `verify()`, `resend()` e criação da conta aprovada. |
| SMS | `app/Support/PhoneVerificationService.php` | Estado da verificação, TTL, tentativas, cooldown e sessão. |
| SMS | `app/Support/PhoneNumber.php` | Normaliza (DDD + número), valida e converte para E.164. |
| SMS | `app/Support/Sms/Contracts/SmsVerificationProvider.php` | Contrato `send()` / `check()`. |
| SMS | `app/Support/Sms/TwilioVerifyProvider.php` | Implementação Twilio Verify e tradução dos erros. |
| SMS | `app/Support/Sms/UnavailableSmsVerificationProvider.php` | Adaptador neutro quando faltam credenciais. |
| SMS | `app/Support/Sms/SmsVerificationException.php` | Falha de infraestrutura (`sendFailed`, `checkFailed`, `unavailable`). |
| Bônus | `app/Support/FirstAccessBonus.php` | `summary()`, `save()` (auditado) e `grantFor()` (idempotente). |
| Bônus | `app/Http/Controllers/Admin/RegistrationBonusController.php` | Tela `/admin/financeiro/bonus` (`edit`/`update`). |
| Bônus | `app/Http/Requests/Admin/UpdateFirstAccessBonusRequest.php` | Validação: ativar exige ≥ 1 crédito e tarifa definida. |
| Requests | `app/Http/Requests/Auth/RegisterRequest.php` | Normaliza nome/e-mail/telefone e valida DDD. |
| Requests | `app/Http/Requests/Auth/ConfirmPhoneVerificationRequest.php` | Aceita código colado com espaços/hífens; exige `code_length` dígitos. |
| Modelos | `app/Models/PhoneVerification.php`, `app/Models/User.php` | Payload do cadastro pendente; `google_id` no `$fillable` e `dashboardRouteName()`. |
| Enums | `app/Enums/PhoneVerificationStatus.php`, `app/Enums/SmsVerificationResult.php` | Situação local e resultado da conferência. |
| Views | `resources/views/components/ui/google-auth-button.blade.php` | Botão + divisor "ou", renderizado só com Google configurado. |
| Views | `resources/views/auth/verify-phone.blade.php` | Etapa do código, validade, tentativas e reenvio com contagem. |
| Views | `resources/views/admin/finance/bonus.blade.php` | Painel do bônus (indicadores + formulário). |
| JS | `resources/js/auth-verification.js` | Máscara de telefone, dígitos no código e contagem do reenvio. |

## 3. Rotas

| Método | Rota | Nome | Middleware | Função |
|---|---|---|---|---|
| GET | `/register` | `register` | `guest` | Formulário de cadastro. |
| POST | `/register` | — | `guest` | Com SMS ativo: cria verificação pendente e envia o código; sem SMS: cria a conta direto. |
| GET | `/register/confirmar-celular` | `register.verify` | `guest` | Tela do código (sem verificação pendente, volta ao cadastro com erro). |
| POST | `/register/confirmar-celular` | `register.verify.store` | `guest`, `throttle:10,10` | Confere o código e, se aprovado, cria a conta. |
| POST | `/register/confirmar-celular/reenviar` | `register.verify.resend` | `guest`, `throttle:5,10` | Reenvia o código respeitando o cooldown. |
| GET | `/auth/google` | `auth.google.redirect` | `guest`, `throttle:20,1` | Redireciona ao consentimento do Google. |
| GET | `/auth/google/callback` | `auth.google.callback` | `guest`, `throttle:20,1` | Recebe o retorno, resolve a conta e autentica. |
| GET | `/admin/financeiro/bonus` | `admin.finance.bonus.edit` | `auth`, `role:admin` | Painel do bônus de primeiro acesso. |
| PATCH | `/admin/financeiro/bonus` | `admin.finance.bonus.update` | `auth`, `role:admin` | Salva ativo/créditos com auditoria. |

## 4. Configuração do Google

```dotenv
GOOGLE_LOGIN_ENABLED=true
GOOGLE_CLIENT_ID=000000000000-xxxxxxxxxxxxxxxx.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=GOCSPX-xxxxxxxxxxxxxxxx
GOOGLE_REDIRECT_URI=https://seudominio.com/auth/google/callback
```

| Chave (`config('...')`) | Variável | Obrigatória | Uso |
|---|---|---|---|
| `authentication.google.enabled` | `GOOGLE_LOGIN_ENABLED` | sim | Liga o botão e as rotas. `false` mantém tudo oculto. |
| `services.google.client_id` | `GOOGLE_CLIENT_ID` | sim | Client ID do OAuth. |
| `services.google.client_secret` | `GOOGLE_CLIENT_SECRET` | sim | Client Secret do OAuth. |
| `services.google.redirect` | `GOOGLE_REDIRECT_URI` | recomendada | Precisa ser **idêntica** à cadastrada no Google. Vazia = Socialite usa a URL da própria requisição de callback (exige `APP_URL`/HTTPS corretos). |
| `authentication.google.scopes` | — | não | `['openid', 'profile', 'email']`. |

O botão só aparece (e as rotas só funcionam) quando a flag **e** as duas
credenciais estão preenchidas — ver `AuthenticationSettings::googleEnabled()`.

### 4.1 No Google Cloud Console

1. Acesse <https://console.cloud.google.com> e crie (ou selecione) um projeto.
2. **APIs e serviços → Tela de consentimento OAuth**: tipo **External**, nome do
   app, e-mail de suporte e domínio autorizado (ex.: `arcana.tarot`). Em
   *Escopos*, adicione `.../auth/userinfo.email` e `.../auth/userinfo.profile`
   (equivalem a `openid profile email`). Em *Usuários de teste*, inclua as contas
   que vão validar enquanto o app estiver em **Testing**.
3. **APIs e serviços → Credenciais → Criar credenciais → ID do cliente OAuth →
   Aplicativo da Web**.
4. Em **URIs de redirecionamento autorizados**, cadastre uma linha por ambiente:
   - local: `http://localhost:8000/auth/google/callback` (ajuste a porta do
     `APP_PORT`/`php artisan serve`);
   - produção: `https://seudominio.com/auth/google/callback`.

   O caminho é fixo (`/auth/google/callback`); a URL é montada por
   `GOOGLE_REDIRECT_URI` ou, na ausência dele, a partir da requisição.
5. Copie **Client ID** e **Client Secret** para o `.env` e rode
   `php artisan config:clear`.
6. Enquanto o app estiver em *Testing*, só as contas de teste conseguem entrar;
   publique (*Publish app*) para liberar o público geral.

> Erro `redirect_uri_mismatch` no Google = URL cadastrada diferente da usada pela
> aplicação (protocolo, domínio, porta ou caminho). Corrija no console e limpe o
> cache de configuração.

## 5. Configuração do SMS (Twilio Verify)

```dotenv
SMS_VERIFICATION_ENABLED=true
SMS_VERIFICATION_PROVIDER=twilio_verify
SMS_VERIFICATION_COUNTRY_CODE=55
SMS_VERIFICATION_CODE_LENGTH=6
SMS_VERIFICATION_TTL_MINUTES=10
SMS_VERIFICATION_MAX_ATTEMPTS=5
SMS_VERIFICATION_RESEND_COOLDOWN_SECONDS=60

# Use Auth Token OU API Key (API Key é revogável e recomendada).
TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_AUTH_TOKEN=
TWILIO_API_KEY_SID=SKxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_API_KEY_SECRET=
TWILIO_VERIFY_SERVICE_SID=VAxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

| Chave (`config('...')`) | Variável | Padrão | Uso |
|---|---|---|---|
| `authentication.sms.enabled` | `SMS_VERIFICATION_ENABLED` | `false` | Liga a segunda etapa do cadastro. |
| `authentication.sms.provider` | `SMS_VERIFICATION_PROVIDER` | `twilio_verify` | Provedor do binding (`AppServiceProvider`). |
| `authentication.sms.country_code` | `SMS_VERIFICATION_COUNTRY_CODE` | `55` | DDI usado por `PhoneNumber::toE164()`. |
| `authentication.sms.code_length` | `SMS_VERIFICATION_CODE_LENGTH` | `6` | Tamanho aceito no formulário (4–8). Precisa casar com o tamanho configurado no Verify Service (padrão **6**). |
| `authentication.sms.ttl_minutes` | `SMS_VERIFICATION_TTL_MINUTES` | `10` | Validade local do código (mínimo 1). |
| `authentication.sms.max_attempts` | `SMS_VERIFICATION_MAX_ATTEMPTS` | `5` | Tentativas antes de invalidar a verificação. |
| `authentication.sms.resend_cooldown_seconds` | `SMS_VERIFICATION_RESEND_COOLDOWN_SECONDS` | `60` | Intervalo mínimo entre envios. |
| `services.twilio.account_sid` | `TWILIO_ACCOUNT_SID` | — | Conta Twilio (`AC...`). |
| `services.twilio.auth_token` | `TWILIO_AUTH_TOKEN` | — | Alternativa à API Key. |
| `services.twilio.api_key_sid` | `TWILIO_API_KEY_SID` | — | API Key (`SK...`), tem prioridade sobre o Auth Token. |
| `services.twilio.api_key_secret` | `TWILIO_API_KEY_SECRET` | — | Secret da API Key. |
| `services.twilio.verify_service_sid` | `TWILIO_VERIFY_SERVICE_SID` | — | Verify Service (`VA...`). |

### 5.1 No console da Twilio

1. Crie a conta em <https://console.twilio.com> (o *trial* já permite testar).
2. **Verify → Services → Create new Service** (ex.: `Arcana Tarot`), canal
   **SMS**. Copie o **Service SID** (`VA...`).
3. Credenciais em **Account → API keys & tokens → Create API key** (tipo
   *Standard*): guarde `SK...` e o secret. Alternativamente use o **Auth Token**
   da conta (menos seguro, não revogável individualmente).
4. Copie o **Account SID** (`AC...`).
5. **Conta trial**: só envia para números verificados em
   **Phone Numbers → Verified Caller IDs**, e o SMS chega com o aviso de trial.
   Faça upgrade para liberar qualquer número.
6. Se o SMS não chegar, verifique **Messaging → Settings → Geo permissions**
   (Brasil habilitado) e o saldo da conta.

### 5.2 Como o binding se comporta

| Situação | Resultado |
|---|---|
| `provider=twilio_verify` + Account SID + Service SID + (API Key **ou** Auth Token) | `TwilioVerifyProvider` real. |
| `provider=twilio_verify` sem alguma dessas credenciais | `UnavailableSmsVerificationProvider`: o cadastro volta com erro amigável no campo `phone`, sem quebrar a aplicação. |
| `provider` diferente de `twilio_verify` com SMS **ligado** | `InvalidArgumentException` no binding: falha explícita para não subir com provedor não implementado. |
| SMS desligado | O passo de SMS não é usado; o cadastro segue em uma etapa. |

> Com `SMS_VERIFICATION_ENABLED=true` e credenciais ausentes a aplicação **não**
> cai, mas nenhum cliente consegue concluir o cadastro. Confira o `.env` antes de
> ligar a flag em produção.
>
> `SMS_VERIFICATION_TTL_MINUTES` é a validade **local**. Ela deve ser menor ou
> igual à validade configurada no Verify Service (padrão do Twilio: 10 minutos);
> um TTL local maior faria o cliente ver o campo liberado para um código que o
> provedor já descartou.

## 6. Fluxos

### 6.1 Login e cadastro com Google

1. O cliente clica em **Continuar com Google** (componente
   `x-ui.google-auth-button`, presente em `/login` e `/register`; sem
   credenciais ele nem é renderizado).
2. `GET /auth/google` → `GoogleAuthenticationController::redirect()` aplica os
   escopos `openid profile email` e redireciona ao consentimento.
3. No retorno, `GET /auth/google/callback?code=...&state=...`:
   - `InvalidStateException` (state expirado/adulterado) → volta ao login com
     "Sua sessão de login com o Google expirou. Tente novamente.";
   - qualquer outra falha do provider → `report()` + mensagem genérica pedindo
     e-mail/senha ou nova tentativa.
4. `GoogleAccountManager::resolve(sub, email, name, emailVerified)` decide:

| Situação | Resultado |
|---|---|
| `sub` já vinculado a uma conta | Login nessa conta. |
| Conta **inativa** | Erro "conta desativada" (não autentica). |
| `email_verified` falso/ausente como `false` | Erro de e-mail não verificado. |
| E-mail existe, `google_id` nulo | **Vincula** a conta: grava o `sub`, mantém senha, telefone e histórico; preenche `email_verified_at` se estiver vazio. |
| E-mail existe com **outro** `google_id` | Erro de conta já vinculada a outro Google. |
| E-mail novo | Cria cliente ativo com `email_verified_at = now()`, `google_id`, senha aleatória de 64 caracteres (definível via "Esqueceu a senha?"), papel `client` e **carteira vazia**. |

5. Conta recém-criada dispara o evento `Registered` e tenta o bônus de primeiro
   acesso. Erro no bônus é registrado e **não** impede o login.
6. `Auth::login($user, true)` + `session()->regenerate()`, com redirect para a
   rota do painel conforme o papel (`User::dashboardRouteName()`).

### 6.2 Cadastro por e-mail com confirmação de celular

1. `POST /register` (`RegisterRequest`): nome/e-mail normalizados, telefone
   normalizado para DDD + número (10 ou 11 dígitos) e validado por
   `PhoneNumber::isValid()` (DDD 1–9; celular `9XXXXXXXX` ou fixo `2–9XXXXXXX`).
   A data de nascimento (18+) e o sexo (`Gender`: feminino, masculino ou outro)
   são obrigatórios.
2. Com SMS ativo, `PhoneVerificationService::start()` grava a verificação
   **pendente** (`phone_verifications`) com `payload = {name, email,
   birth_date, gender, password_hash}` (hash, nunca a senha) e `expires_at`,
   envia o código em E.164 e guarda o id na sessão.
3. `GET /register/confirmar-celular` mostra o número mascarado
   (`(11) *****-1234`), validade em minutos, tentativas restantes e o botão de
   reenvio com contagem regressiva.
4. `POST /register/confirmar-celular` confere o código:

| Resultado | Efeito |
|---|---|
| `Approved` | Verificação `approved` + `consumed_at`; conta criada com `phone` e `phone_verified_at`, carteira, evento `Registered`, bônus e login. |
| `Invalid` | `attempts++`, segue `pending`; mensagem com tentativas restantes. |
| `Expired` | Verificação `expired`; pede novo código. |
| `TooManyAttempts` | Verificação `failed`; volta ao cadastro para recomeçar (novo número/código). |

5. Erro de infraestrutura (`SmsVerificationException`) → `report()` e mensagem
   "Não foi possível conferir o código agora", mantendo o cliente na tela.
6. `POST /register/confirmar-celular/reenviar` só envia depois do cooldown
   (calculado a partir de `updated_at`); ao reenviar, a verificação volta a
   `pending`, `attempts = 0`, `consumed_at = null` e `expires_at` é renovado.

> O e-mail só é "ocupado" na aprovação. Se outra conta usar o mesmo e-mail nesse
> intervalo, a criação é abortada com "Já existe uma conta com este e-mail" e a
> verificação é descartada.

### 6.3 Bônus de primeiro acesso

- Precedência de configuração: `settings.first_access_bonus_enabled` e
  `settings.first_access_bonus_credits` (salvos pela tela admin) sobre
  `config('authentication.first_access_bonus.*')` (valores de instalação).
- Valor do bônus = `credits × credit_unit_price_cents`. Sem tarifa de crédito
  definida (ou com 0 crédito), **não há bônus**: o cadastro continua e a
  tentativa é registrada em `audit_logs` com `action = first_access_bonus.skipped`
  e `reason = credit_unit_price_missing` ou `no_credits_configured`.
- A concessão usa `CreditWalletManager::credit()` com a chave de idempotência
  `first_access_bonus:user:{id}`; se ela já existir, retorna `null` e nenhum
  crédito é somado.
- Só se aplica a **clientes** (`role = client`) e a contas novas: o bônus é
  tentado no cadastro por e-mail (direto ou após SMS) e no primeiro login
  Google que cria a conta.
- Cada concessão gera `audit_logs` com `action = first_access_bonus.granted`,
  `credits`, `amount_cents` e `ledger_entry_id`.

## 7. Modelo de dados

### 7.1 `users`

| Coluna | Tipo | Observação |
|---|---|---|
| `phone` | string(20), nullable | DDD + número, sem máscara (já existia). |
| `phone_verified_at` | timestamp, nullable | Preenchido apenas na aprovação por SMS (já existia). |
| `google_id` | string, nullable, **unique** | `sub` do Google; migration `2026_11_07_120100_add_google_id_to_users_table`. |

### 7.2 `phone_verifications`

Migration `2026_11_07_120000_create_phone_verifications_table`.

| Coluna | Uso |
|---|---|
| `phone` | DDD + número (E.164 é derivado no envio). |
| `status` | `pending`, `approved`, `expired`, `failed` (`PhoneVerificationStatus`). |
| `attempts` | Quantidade de conferências já feitas. |
| `payload` | JSON com `name`, `email`, `birth_date`, `gender` e `password_hash` até a aprovação. |
| `expires_at` / `consumed_at` | Validade local e momento do consumo. |
| `ip_address` | Origem do pedido (auditoria/abuso). |

Índices: `(phone, status)` e `expires_at`. Linhas antigas não são usadas para
login: `PhoneVerificationService::pending()` só aceita `pending` com
`consumed_at` nulo.

### 7.3 `settings` e `audit_logs`

| Origem | Chave / ação |
|---|---|
| `settings` | `first_access_bonus_enabled` (`0`/`1`) e `first_access_bonus_credits` (inteiro). |
| `settings` | `credit_unit_price_cents` (tarifa usada no cálculo do valor). |
| `audit_logs` | `first_access_bonus.updated` (`before`/`after` + `actor_id`). |
| `audit_logs` | `first_access_bonus.granted` (`credits`, `amount_cents`, `ledger_entry_id`). |
| `audit_logs` | `first_access_bonus.skipped` (`reason`, `credits`). |
| `credit_ledger_entries` | `idempotency_key = first_access_bonus:user:{id}`, descrição do bônus. |

## 8. Telas

- **`/login` e `/register`**: componente `x-ui.google-auth-button` (divisor "ou"
  + botão), renderizado somente com Google configurado. O campo de telefone do
  cadastro usa `data-phone-mask` e o texto de ajuda muda quando o SMS está ativo.
- **`/register/confirmar-celular`**: código com `inputmode="numeric"` e
  `autocomplete="one-time-code"`, validade/tentativas no texto de ajuda, botão de
  reenvio travado pelo cooldown (`data-resend-button`) e link "Recomeçar
  cadastro".
- **`/admin/financeiro/bonus`** ("Bônus de cadastro" no menu lateral, título
  "Bônus de primeiro acesso"): quatro indicadores (situação, créditos por
  cliente, valor do bônus e total já concedido) e o formulário com o switch
  "Ativar bônus de primeiro acesso" + campo "Créditos por cliente" (limite
  `FirstAccessBonus::MAX_CREDITS`, 1000). O card "Valor do bônus" mostra o
  cálculo (`créditos × valor do crédito`) ou "Não calculado" quando falta a
  tarifa; "Já concedido" conta os lançamentos do prefixo
  `first_access_bonus:user:`.

## 9. Testes automatizados

| Arquivo | Cobre |
|---|---|
| `tests/Feature/GoogleAuthenticationTest.php` | Bloqueio sem credenciais, redirect ao provider, criação de cliente, vínculo com conta existente, conflito de `google_id`, conta inativa, e-mail não verificado, `InvalidStateException`, falha genérica do provider e bônus (novo x existente). |
| `tests/Feature/SmsRegistrationTest.php` | Cadastro pendente até a aprovação, código inválido contando tentativas, limite de tentativas, expiração, código com tamanho errado recusado antes do provedor, cooldown/reenvio, falha do provedor no envio e na conferência, sessão obrigatória, cadastro em uma etapa sem SMS e bônus só após aprovação. |
| `tests/Feature/FirstAccessBonusTest.php` | Bônus desligado, concessão única com auditoria e livro-razão, ausência de tarifa (skip auditado), não-clientes, tela admin (renderização, persistência, `before`/`after`, ativar/desativar, autorização `403` para cliente e validações). |

Comandos (Docker do projeto):

```bash
docker compose exec --user 1000:1000 -e HOME=/tmp app \
  php artisan test --compact tests/Feature/GoogleAuthenticationTest.php

docker compose exec --user 1000:1000 -e HOME=/tmp app \
  php artisan test --compact tests/Feature/SmsRegistrationTest.php

docker compose exec --user 1000:1000 -e HOME=/tmp app \
  php artisan test --compact tests/Feature/FirstAccessBonusTest.php

# suíte completa
docker compose exec --user 1000:1000 -e HOME=/tmp app php artisan test --compact
```

Os testes usam `RefreshDatabase` com SQLite em memória (`phpunit.xml`) e
substituem o Google (`Socialite` factory) e o provedor de SMS por dublês — nada
sai para a internet, nenhum SMS é cobrado.

## 10. Roteiro de validação manual

### 10.1 Preparação

```bash
# 1. migrations (cria phone_verifications e users.google_id)
docker compose exec --user 1000:1000 -e HOME=/tmp app php artisan migrate

# 2. limpar cache de configuração após editar o .env
docker compose exec --user 1000:1000 -e HOME=/tmp app php artisan config:clear

# 3. garantir a tarifa do crédito (necessária para o bônus)
docker compose exec --user 1000:1000 -e HOME=/tmp app php artisan tinker \
  --execute 'App\Models\Setting::updateOrCreate(["key" => "credit_unit_price_cents"], ["value" => "200", "type" => "integer"]);'
```

Use um e-mail/telefone reais seus e uma conta de administrador existente
(`php artisan db:seed` cria as contas fictícias descritas em
[SEEDERS_E_DADOS_DE_TESTE.md](SEEDERS_E_DADOS_DE_TESTE.md)).

### 10.2 Google

1. Com `GOOGLE_LOGIN_ENABLED=false`, abra `/login`: **nenhum** botão do Google.
   Acesse `/auth/google` na mão → volta ao login com erro amigável.
2. Ligue a flag, preencha as credenciais, rode `config:clear` e recarregue
   `/login` e `/register`: botão "Continuar com Google" visível.
3. Clique e conclua o consentimento com uma conta **nova** → deve cair no painel
   do cliente com "Conta criada com o Google". Confira no banco: `google_id`
   preenchido, `email_verified_at` não nulo, papel `client`, carteira criada e,
   se o bônus estiver ativo, o saldo creditado.
4. Saia e entre novamente com a **mesma** conta Google → login sem duplicar
   usuário e **sem** novo crédito de bônus.
5. Crie uma conta por e-mail/senha, saia e entre com o Google usando o mesmo
   e-mail → a conta existente é vinculada (senha antiga continua válida) e não há
   duplicação.
6. Desative um usuário (`is_active = false`) e tente entrar com o Google → deve
   ser recusado.
7. (Opcional) Cancele o consentimento no Google → volta ao login com mensagem de
   falha, sem erro 500.

### 10.3 SMS

> Teste com SMS real só em ambiente de teste: cada código enviado é cobrado
> (no trial, apenas números verificados recebem).

1. Com `SMS_VERIFICATION_ENABLED=false`, cadastre-se em `/register` → conta
   criada imediatamente (uma etapa).
2. Ligue a flag com as credenciais da Twilio e recarregue `/register`: o texto de
   ajuda do telefone passa a avisar sobre o código por SMS.
3. Preencha o cadastro com **seu** celular → é levado a
   `/register/confirmar-celular` com o número mascarado; o SMS chega.
4. Digite um código **errado** → mensagem com "Restam N tentativa(s)" e o
   contador caindo a cada erro até voltar ao cadastro quando esgotar.
5. Digite o código **correto** → conta criada e autenticada; confira
   `phone_verified_at`, a carteira e o bônus (se ativo). Note que a conta **não**
   existia antes da aprovação (compare `phone_verifications` e `users`).
6. Toque em **Reenviar código** antes do cooldown → mensagem pedindo para
   aguardar; depois do prazo, o reenvio funciona e zera as tentativas.
7. Deixe o código expirar (`SMS_VERIFICATION_TTL_MINUTES=1` para testar) →
   mensagem de expiração e novo envio disponível.
8. Simule falha de credencial (remova `TWILIO_AUTH_TOKEN`/API Key e rode
   `config:clear`) → o cadastro volta com "Não foi possível enviar o código por
   SMS agora" e o erro real aparece em `storage/logs/laravel.log`.
9. Sem sessão pendente, acesse `/register/confirmar-celular` direto → volta ao
   cadastro pedindo os dados novamente.

### 10.4 Bônus de primeiro acesso

1. Entre como **admin** em `/admin/financeiro/bonus` (menu "Bônus de cadastro").
2. Tente ativar com 0 crédito → erro no campo de créditos.
3. Zere a tarifa do crédito (ou use um banco sem `credit_unit_price_cents`) e
   tente ativar → erro pedindo para definir o valor do crédito no Financeiro.
4. Defina, por exemplo, tarifa `R$ 2,00` e bônus de `3` créditos → "Valor do
   bônus" deve mostrar `R$ 6,00`.
5. Cadastre um cliente novo (Google ou SMS) → saldo `R$ 6,00`, um lançamento com
   `idempotency_key = first_access_bonus:user:{id}` e um `audit_logs` de
   `first_access_bonus.granted`.
6. Repita o login/cadastro do mesmo cliente → nenhum crédito novo.
7. Desative o bônus e cadastre outro cliente → saldo zero e nenhum lançamento.
8. Confirme que um **atendente** nunca recebe bônus.

## 11. Problemas comuns

| Sintoma | Causa provável | Ação |
|---|---|---|
| Botão do Google não aparece | Flag `false`, credencial vazia ou cache de config | Preencher `.env` e rodar `php artisan config:clear`. |
| `redirect_uri_mismatch` no Google | URL não cadastrada/divergente | Cadastrar exatamente a URL de callback do ambiente. |
| "O login com Google não está disponível neste momento" | Rotas acessadas sem credenciais válidas | Conferir `AuthenticationSettings::googleEnabled()`. |
| "Sua sessão de login com o Google expirou" | State OAuth expirado/adulterado ou cookie de sessão perdido | Repetir o login; conferir `SESSION_DOMAIN`/HTTPS. |
| E-mail já vinculado a outro Google | `google_id` diferente para o mesmo e-mail | Orientar login pelo Google correto ou tratar manualmente. |
| SMS não chega | Número não verificado (trial), Service SID errado, geo permissions ou saldo | Verificar o console da Twilio e `storage/logs/laravel.log`. |
| "A confirmação por SMS está ativada, mas as credenciais..." | `UnavailableSmsVerificationProvider` em uso | Preencher `TWILIO_*` ou desligar a flag. |
| Cadastro volta pedindo os dados | Verificação expirada/consumida ou sessão perdida | Recomeçar o cadastro. |
| Bônus não creditado | Bônus desligado, sem tarifa de crédito, usuário não é cliente ou já recebeu | Ver `audit_logs` (`skipped`/`granted`) e a carteira. |
| Erro de coluna `google_id` inexistente | Migration pendente | `php artisan migrate`. |

## 12. Segurança e privacidade

- Senha de conta criada pelo Google é aleatória (64 caracteres); o cliente a
  redefine por "Esqueceu a senha?".
- No SMS, `phone_verifications.payload` guarda apenas o **hash** da senha.
- O número exibido na confirmação é mascarado (DDD + 4 últimos dígitos) e o
  código nunca é logado nem persistido.
- `session()->regenerate()` no login Google e na aprovação por SMS (proteção
  contra fixação de sessão).
- Throttle por rota: `20/min` no fluxo Google, `10/10min` na confirmação e
  `5/10min` no reenvio, além do cooldown de negócio.
- `google_id` único e bloqueio de contas inativas no login social.
- Erros do provedor vão para o log via `report()`, com mensagem genérica ao
  cliente (sem vazar credenciais ou detalhes internos).
- Toda alteração de configuração do bônus é auditada com ator e valores
  antes/depois; a concessão é auditada e idempotente no livro-razão.

## 13. Referências

- [WALLET_LEDGER.md](WALLET_LEDGER.md) — carteira, livro-razão e idempotência.
- [SECURITY_PRIVACY.md](SECURITY_PRIVACY.md) — controles e riscos residuais.
- [DEPLOYMENT.md](DEPLOYMENT.md) — variáveis de ambiente e deploy.
- [SEEDERS_E_DADOS_DE_TESTE.md](SEEDERS_E_DADOS_DE_TESTE.md) — contas fictícias.
- Twilio Verify API: <https://www.twilio.com/docs/verify/api>
- Laravel Socialite: <https://laravel.com/docs/socialite>


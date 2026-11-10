# Autenticação com Google, confirmação de e-mail e bônus de primeiro acesso

> Login/cadastro com Google (OAuth 2.0 via Socialite), cadastro por e-mail com
> confirmação por link assinado (Laravel `MustVerifyEmail`) e bônus configurável
> de créditos no primeiro acesso. Este documento descreve as garantias, a
> configuração das credenciais, os fluxos, o modelo de dados, os testes
> automatizados e o roteiro de validação manual.

## 1. Escopo e garantias

- O login por **e-mail e senha continua sendo o caminho principal**, e agora
  exige um e-mail de provedor aceito (`gmail.com`, `hotmail.com`, `outlook.com`
  ou `ig.com.br`). Google é a alternativa opcional: sem credenciais válidas, o
  botão não é renderizado — a aplicação nunca mostra uma tela que terminaria em
  erro de configuração.
- **Nenhum método alternativo cria conta sem identidade verificada**: no Google
  a claim `email_verified` precisa ser verdadeira; no cadastro por formulário a
  conta nasce sem acesso aos painéis e só é liberada quando o link enviado por
  e-mail é aberto.
- **`users.google_id` é o `sub` do Google**, não o e-mail. O mesmo cliente
  continua reconhecido mesmo que troque o endereço na conta Google. A coluna tem
  índice único, então duas contas locais nunca compartilham a mesma identidade.
- **O link de confirmação é uma URL assinada do framework**, gerada a partir do
  id da conta e do `sha1` do e-mail; a validade vem de
  `auth.verification.expire` (`EMAIL_VERIFICATION_EXPIRE_MINUTES`, padrão 60).
  Assinatura inválida, hash de outro endereço ou link vencido respondem `403`.
- **A conta é criada na hora; o acesso é o que fica pendente.** O cliente já
  autenticado vê o aviso "Confirme seu e-mail", com botão de reenvio
  (`throttle:6,1`), e só entra nos painéis depois da confirmação (middleware
  `verified`). Sair e ajustar preferências continuam liberados antes disso.
- **Falha de SMTP não derruba o cadastro**: o envio do link fica em `try/catch`,
  o erro vai para o log via `report()` e a tela de aviso oferece o reenvio.
- **O bônus é idempotente e nunca bloqueia o acesso**: a chave
  `first_access_bonus:user:{id}` garante uma única concessão e qualquer exceção
  ao conceder é registrada no log, sem impedir a confirmação do e-mail.
- **Nenhum valor de bônus vem do navegador**: o crédito é calculado pela tarifa
  vigente do crédito (`credit_unit_price_cents`).

## 2. Componentes

| Camada | Arquivo | Papel |
|---|---|---|
| Configuração | `config/authentication.php` | Blocos `google`, `registration` (domínios aceitos no formulário) e `first_access_bonus` (valores de instalação). |
| Configuração | `config/auth.php` | `verification.expire` — validade do link (`EMAIL_VERIFICATION_EXPIRE_MINUTES`). |
| Configuração | `config/services.php` | Chave `google`. |
| Gate de exibição | `app/Support/AuthenticationSettings.php` | `googleEnabled()` (flag + credenciais). |
| Google | `app/Http/Controllers/Auth/GoogleAuthenticationController.php` | `redirect()` e `callback()`, tratamento de `InvalidStateException` e falhas do provider. |
| Google | `app/Support/GoogleAccountManager.php` | Resolve `sub`/e-mail em conta local (vínculo, criação, bloqueio). |
| Google | `app/Support/GoogleAccountException.php` | Erros de domínio com mensagem pronta para o cliente. |
| E-mail | `app/Http/Controllers/Auth/RegisteredUserController.php` | `create()` e `store()`: cria a conta, abre a carteira, autentica e dispara o link de confirmação. |
| E-mail | `app/Http/Requests/Auth/RegisterRequest.php` | Normaliza nome/e-mail/telefone, valida DDD e aplica `AllowedEmailDomain`. |
| E-mail | `app/Rules/AllowedEmailDomain.php` | Regra que recusa domínios fora da lista configurada. |
| E-mail | `app/Support/AllowedEmailDomains.php` | Lista de domínios (configuração, comparação e mensagens do formulário). |
| E-mail | `app/Support/PhoneNumber.php` | Normaliza (DDD + número), valida e converte para E.164 (checkout). |
| E-mail | `app/Http/Controllers/Auth/EmailVerificationController.php` | `notice()`, `verify()` (confirma o e-mail e concede o bônus) e `send()` (reenvio). |
| E-mail | `app/Notifications/Auth/VerifyEmailNotification.php` | Notificação do link, com o template da aplicação em vez do layout padrão. |
| E-mail | `app/Models/User.php` | `implements MustVerifyEmail` + `sendEmailVerificationNotification()`. |
| Bônus | `app/Support/FirstAccessBonus.php` | `summary()`, `save()` (auditado) e `grantFor()` (idempotente). |
| Bônus | `app/Http/Controllers/Admin/RegistrationBonusController.php` | Tela `/admin/financeiro/bonus` (`edit`/`update`). |
| Bônus | `app/Http/Requests/Admin/UpdateFirstAccessBonusRequest.php` | Validação: ativar exige ≥ 1 crédito e tarifa definida. |
| Views | `resources/views/components/ui/google-auth-button.blade.php` | Botão + divisor "ou", renderizado só com Google configurado. |
| Views | `resources/views/auth/verify-email.blade.php` | Aviso "Confirme seu e-mail": reenvio, saída e prazo do link. |
| Views | `resources/views/mail/verify-email.blade.php` | Corpo HTML do e-mail, com estilos inline e o botão de confirmação. |
| Views | `resources/views/admin/finance/bonus.blade.php` | Painel do bônus (indicadores + formulário). |
| JS | `resources/js/auth-verification.js` | Máscara do telefone no cadastro. |

## 3. Rotas

| Método | Rota | Nome | Middleware | Função |
|---|---|---|---|---|
| GET | `/register` | `register` | `guest` | Formulário de cadastro (domínios aceitos no texto de ajuda). |
| POST | `/register` | — | `guest`, `throttle:10,10` | Cria a conta, abre a carteira, autentica e envia o link de confirmação. |
| GET | `/email/verificar` | `verification.notice` | `auth` | Aviso "Confirme seu e-mail" com reenvio (quem já confirmou vai ao painel). |
| GET | `/email/verificar/{id}/{hash}` | `verification.verify` | `auth`, `signed`, `throttle:6,1` | Confirma o e-mail, concede o bônus e leva ao painel. |
| POST | `/email/verification-notification` | `verification.send` | `auth`, `throttle:6,1` | Reenvia o link de confirmação. |
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

## 5. Cadastro por e-mail e confirmação do link

```dotenv
# Provedores aceitos no formulário (lista separada por vírgula, sem "@").
REGISTRATION_ALLOWED_EMAIL_DOMAINS=gmail.com,hotmail.com,outlook.com,ig.com.br

# Validade do link de confirmação, em minutos.
EMAIL_VERIFICATION_EXPIRE_MINUTES=60
```

| Chave (`config('...')`) | Variável | Padrão | Uso |
|---|---|---|---|
| `authentication.registration.allowed_email_domains` | `REGISTRATION_ALLOWED_EMAIL_DOMAINS` | `gmail.com,hotmail.com,outlook.com,ig.com.br` | Lista usada por `AllowedEmailDomain` e pelo texto de ajuda do formulário. |
| `auth.verification.expire` | `EMAIL_VERIFICATION_EXPIRE_MINUTES` | `60` | Validade da URL assinada gerada por `VerifyEmailNotification`. |
| `mail.*` | `MAIL_*` | — | Transporte do e-mail (SMTP/Mailpit). Sem transporte funcional o cadastro conclui, mas o link não chega. |

### 5.1 Regra de domínio

- A comparação considera **apenas o domínio**, em minúsculas e por igualdade
  exata: `Maria@GMAIL.COM` passa (normalizado para `maria@gmail.com` por
  `RegisterRequest`), mas `maria@gmail.com.br`, `maria@sub.gmail.com` e
  `maria@example.com` não.
- A lista é lida a cada requisição (`config()`), então mudar o `.env` e rodar
  `php artisan config:clear` basta para incluir ou remover um provedor.
- A regra vale **somente** para o cadastro por formulário. Contas criadas pelo
  Google mantêm o domínio que o provedor devolveu, porque o e-mail já vem
  verificado.
- A mesma lista alimenta a mensagem de erro e o texto de ajuda do campo, via
  `AllowedEmailDomains::message()`, para não haver divergência entre os dois.

### 5.2 Transporte de e-mail

O link sai pela configuração `MAIL_*` do projeto
(`App\Notifications\Auth\VerifyEmailNotification` → view
`mail.verify-email`). Em desenvolvimento o `compose.yaml` sobe o Mailpit: abra
`http://localhost:8025` para ler a mensagem. Em produção, aponte o SMTP do
provedor e confirme `MAIL_FROM_ADDRESS` — sem isso o envio falha e o cliente vê o
aviso de reenvio na tela de confirmação.

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

### 6.2 Cadastro por e-mail com confirmação do link

1. `GET /register` mostra o formulário; o campo de e-mail traz no texto de ajuda
   a lista de provedores aceitos (`AllowedEmailDomains::message()`).
2. `POST /register` (`RegisterRequest`): nome/e-mail normalizados
   (`Str::squish`, `Str::lower`), domínio conferido por `AllowedEmailDomain`,
   telefone normalizado para DDD + número (10 ou 11 dígitos) e validado por
   `PhoneNumber::isValid()` (DDD 1–9; celular `9XXXXXXXX` ou fixo
   `2–9XXXXXXX`). Data de nascimento (18+) e sexo (`Gender`) são obrigatórios.
3. `RegisteredUserController::store()` grava a conta em transação
   (`role = client`, `is_active = true`, `email_verified_at` nulo), abre a
   carteira com `CreditWalletManager::walletFor()` e faz `Auth::login()`.
4. O evento `Registered` aciona o listener do framework, que chama
   `User::sendEmailVerificationNotification()` → `VerifyEmailNotification`
   (URL assinada + view `mail.verify-email`). O redirect vai para
   `verification.notice` com o status "Enviamos um link de confirmação…". Se o
   envio falhar, a conta continua criada e a tela mostra o aviso de reenvio.
5. Enquanto o e-mail não é confirmado, o middleware `verified` leva qualquer
   rota de painel para `verification.notice`. `logout` e as rotas de
   preferência continuam acessíveis antes disso.
6. `POST /email/verification-notification` chama
   `sendEmailVerificationNotification()` de novo (limite `6/min`).
7. `GET /email/verificar/{id}/{hash}`:
   - `signed` recusa URL vencida ou adulterada (`403`);
   - `EmailVerificationRequest` confere `id` e `sha1(email)` — outra conta ou
     outro e-mail também recebe `403`;
   - `markEmailAsVerified()` grava `email_verified_at`, dispara `Verified` e
     concede o bônus; o redirect usa `redirect()->intended()` para o painel do
     papel.

> O mesmo link não confirma duas contas: `id`, `hash` e assinatura precisam
> bater com a sessão autenticada, então abrir a URL logado em outra conta
> responde `403` sem alterar nada.

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
- Só se aplica a **clientes** (`role = client`) e a contas novas: no cadastro por
  e-mail o bônus é concedido na confirmação do link
  (`EmailVerificationController::verify()`) e, no Google, no primeiro login que
  cria a conta.
- Cada concessão gera `audit_logs` com `action = first_access_bonus.granted`,
  `credits`, `amount_cents` e `ledger_entry_id`.

## 7. Modelo de dados

### 7.1 `users`

| Coluna | Tipo | Observação |
|---|---|---|
| `phone` | string(20), nullable | DDD + número, sem máscara; hoje é apenas dado de contato. |
| `phone_verified_at` | timestamp, nullable | Histórico das contas confirmadas por SMS na época em que o fluxo existia; nada novo grava aqui. |
| `email_verified_at` | timestamp, nullable | Preenchido ao abrir o link (Google grava na criação da conta). Migration de backfill: `2026_11_10_141546_mark_existing_users_as_email_verified`. |
| `google_id` | string, nullable, **unique** | `sub` do Google; migration `2026_11_07_120100_add_google_id_to_users_table`. |

> **Backfill:** a migration `mark_existing_users_as_email_verified` preenche
> `email_verified_at` com `COALESCE(updated_at, created_at)` em todas as contas
> que já existiam. Sem isso, o middleware `verified` bloquearia quem já usava a
> plataforma. O `down()` é vazio de propósito: não há como distinguir depois as
> contas marcadas pelo backfill.

### 7.2 `phone_verifications` (removida)

A tabela do fluxo por SMS foi eliminada pela migration
`2026_11_10_141545_drop_phone_verifications_table` (`up()` faz
`Schema::dropIfExists`; o `down()` recria a estrutura original para permitir
rollback). Nenhum dado dela é usado hoje: a confirmação é feita pelo link
assinado e o telefone é apenas cadastral.

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
  + botão), renderizado somente com Google configurado. O campo de e-mail do
  cadastro mostra os provedores aceitos no texto de ajuda; o de telefone usa
  `data-phone-mask` e é apenas contato.
- **`/email/verificar`**: aviso "Confirme seu e-mail" com o endereço de destino,
  botão "Reenviar e-mail" (`throttle:6,1`), botão "Sair" e o prazo do link
  (`auth.verification.expire`).
- **E-mail de confirmação** (`mail/verify-email.blade.php`): identidade visual da
  aplicação, botão de confirmação e a URL também em texto, para clientes que
  bloqueiam botões.
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
| `tests/Feature/EmailVerificationTest.php` | Cadastro cria conta não verificada e envia o link (view e URL), domínio em maiúsculas aceito, domínios fora da lista e subdomínios recusados, lista vinda de `config()`, tela de aviso, link assinado verifica e libera o painel, `403` sem assinatura ou com hash de outro e-mail, expiração após a janela, bloqueio de painéis para não verificados (com `logout` liberado), reenvio com `throttle:6,1` e login de conta não verificada terminando no aviso. |
| `tests/Feature/FirstAccessBonusTest.php` | Bônus desligado, concessão única **na confirmação do e-mail** (o cadastro sozinho não credita), ausência de tarifa (skip auditado), não-clientes, tela admin (renderização, persistência, `before`/`after`, ativar/desativar, autorização `403` para cliente e validações). |
| `tests/Feature/AuthTest.php` | Cadastro completo (conta criada, carteira aberta, sessão iniciada, aviso de confirmação), normalização de nome/e-mail/telefone, idade mínima, telefone obrigatório e `role` ignorado no payload. |

Comandos (Docker do projeto):

```bash
docker compose exec --user 1000:1000 -e HOME=/tmp app \
  php artisan test --compact tests/Feature/GoogleAuthenticationTest.php

docker compose exec --user 1000:1000 -e HOME=/tmp app \
  php artisan test --compact tests/Feature/EmailVerificationTest.php

docker compose exec --user 1000:1000 -e HOME=/tmp app \
  php artisan test --compact tests/Feature/FirstAccessBonusTest.php

# suíte completa
docker compose exec --user 1000:1000 -e HOME=/tmp app php artisan test --compact
```

Os testes usam `RefreshDatabase` com SQLite em memória (`phpunit.xml`),
substituem o Google (`Socialite` factory) e usam `Notification::fake()` para o
link de confirmação — nada sai para a internet e nenhum e-mail real é enviado.
As URLs assinadas são montadas com `URL::temporarySignedRoute()`, o que permite
cobrir assinatura, hash e expiração sem depender do relógio do servidor de e-mail.

## 10. Roteiro de validação manual

### 10.1 Preparação

```bash
# 1. migrations (users.google_id, drop de phone_verifications e backfill de email_verified_at)
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

### 10.3 Confirmação de e-mail

1. Abra `/register`: o texto de ajuda do e-mail lista `@gmail.com`,
   `@hotmail.com`, `@outlook.com` e `@ig.com.br`.
2. Tente `alguem@example.com` → erro no campo de e-mail, sem conta criada.
3. Cadastre-se com um endereço de um desses provedores → a conta é criada, você
   já entra e cai em `/email/verificar` com o endereço de destino.
4. Tente abrir `/client` (ou qualquer painel) antes de confirmar → volta para
   `/email/verificar`. O botão **Sair** continua funcionando.
5. Abra o e-mail (Mailpit em `http://localhost:8025` no ambiente local) e clique
   em **Confirmar meu e-mail** → você é levado ao painel do cliente e
   `users.email_verified_at` passa a ter valor.
6. Antes de confirmar, use **Reenviar e-mail**: o limite é de 6 envios por minuto
   por sessão — o sétimo responde `429`.
7. Peça um link, espere o prazo (`EMAIL_VERIFICATION_EXPIRE_MINUTES=1` para
   testar) e abra a URL → `403`. Use o reenvio na tela para gerar um novo link.
8. Altere a URL na mão (troque o `hash` ou o `id`) → `403`, sem alterar a conta.
9. Com o bônus ativo, repita o cadastro com outro e-mail: o saldo só aparece
   **depois** da confirmação do link.

### 10.4 Bônus de primeiro acesso

1. Entre como **admin** em `/admin/financeiro/bonus` (menu "Bônus de cadastro").
2. Tente ativar com 0 crédito → erro no campo de créditos.
3. Zere a tarifa do crédito (ou use um banco sem `credit_unit_price_cents`) e
   tente ativar → erro pedindo para definir o valor do crédito no Financeiro.
4. Defina, por exemplo, tarifa `R$ 2,00` e bônus de `3` créditos → "Valor do
   bônus" deve mostrar `R$ 6,00`.
5. Cadastre um cliente novo (Google ou por e-mail, abrindo o link de confirmação)
   → saldo `R$ 6,00`, um lançamento com
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
| E-mail de domínio não aceito no cadastro | Lista de provedores (`REGISTRATION_ALLOWED_EMAIL_DOMAINS`) ou erro de digitação | Conferir a mensagem do campo; ajustar a lista e rodar `php artisan config:clear`. |
| Link de confirmação não chega | SMTP sem credencial, `MAIL_FROM_ADDRESS` inválido ou e-mail em spam | Ver `storage/logs/laravel.log`, usar o Mailpit localmente e checar spam. |
| Link responde `403` | URL expirada, assinatura adulterada, hash de outro e-mail ou sessão de outra conta | Usar **Reenviar e-mail** em `/email/verificar` e abrir o link na mesma sessão. |
| Login cai sempre em `/email/verificar` | `email_verified_at` nulo | Abrir o link; contas antigas devem ter recebido o backfill da migration. |
| Contas antigas ficaram bloqueadas | Migration de backfill não rodou | `php artisan migrate` (a migration preenche `email_verified_at` de quem já existia). |
| Bônus não creditado | Bônus desligado, sem tarifa de crédito, usuário não é cliente, e-mail não confirmado ou já recebeu | Ver `audit_logs` (`skipped`/`granted`) e a carteira. |
| Erro de coluna `google_id` inexistente | Migration pendente | `php artisan migrate`. |

## 12. Segurança e privacidade

- Senha de conta criada pelo Google é aleatória (64 caracteres); o cliente a
  redefine por "Esqueceu a senha?".
- O link de confirmação é uma URL **assinada** com a `APP_KEY` e amarrada ao
  `id` da conta + `sha1` do e-mail: vale só para aquela conta, naquela sessão, e
  expira em `EMAIL_VERIFICATION_EXPIRE_MINUTES`. Nada do link é reversível em
  dados pessoais.
- O cadastro não revela quais e-mails já existem além da mensagem padrão de
  `unique`; o reenvio responde igual para qualquer conta pendente.
- `session()->regenerate()` no login (e-mail/senha e Google) — proteção contra
  fixação de sessão.
- Throttle por rota: `20/min` no fluxo Google, `10/10min` no `POST /register`,
  `6/min` no reenvio e na abertura do link.
- `google_id` único e bloqueio de contas inativas no login social.
- Erros de SMTP/provedor vão para o log via `report()`, com mensagem genérica ao
  cliente (sem vazar credenciais ou detalhes internos).
- Toda alteração de configuração do bônus é auditada com ator e valores
  antes/depois; a concessão é auditada e idempotente no livro-razão.

## 13. Referências

- [WALLET_LEDGER.md](WALLET_LEDGER.md) — carteira, livro-razão e idempotência.
- [SECURITY_PRIVACY.md](SECURITY_PRIVACY.md) — controles e riscos residuais.
- [DEPLOYMENT.md](DEPLOYMENT.md) — variáveis de ambiente e deploy.
- [SEEDERS_E_DADOS_DE_TESTE.md](SEEDERS_E_DADOS_DE_TESTE.md) — contas fictícias.
- Verificação de e-mail no Laravel: <https://laravel.com/docs/verification>
- Laravel Socialite: <https://laravel.com/docs/socialite>


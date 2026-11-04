# Arcana Tarot — Teste local no modo Hostoo

> Como exercitar localmente o perfil de produção em **hospedagem compartilhada**
> (sem Redis, sem Reverb, sem worker permanente) antes de subir para o Hostoo.
> Complementa [ARQUITETURA_PRODUCAO_HOSTOO.md](ARQUITETURA_PRODUCAO_HOSTOO.md)
> (arquitetura e runbook de produção) e [DEPLOYMENT.md](DEPLOYMENT.md) (Docker).
>
> Todos os comandos e resultados deste documento foram executados e conferidos
> neste repositório (Laravel 13.38, PHP 8.4.26, MySQL 8.4).

---

## 1. O que este teste cobre

| Eixo | Como fica no modo Hostoo | Este teste reproduz? |
|---|---|---|
| Tempo real | polling HTTP (`data-realtime-mode="poll"`) | **sim** |
| Fila | `QUEUE_CONNECTION=database` + cron `queue:work` | **sim** (cron emulado, §6) |
| Cache e sessão | MySQL (`cache`, `sessions`) | **sim** |
| Agendador | 4 comandos `everyMinute` | **sim** (cron emulado, §6) |
| Assets compilados | `npm run build` → `public/build` | **sim** |
| Proxy/TLS do provedor | termina fora do PHP | **não** (§8) |
| `.htaccess` / LiteSpeed | Apache/LiteSpeed do provedor | **não** (§8) |
| PHP do cron, upload máximo, SMTP | definidos no painel | **não** (§8) |

O objetivo não é substituir o teste no servidor: é chegar no Hostoo com a
aplicação já provada **sem Redis e sem WebSocket**, restando apenas validar o que
depende do painel (D-07 PHP, D-08 SSH, D-06 SMTP, limites de upload).

## 2. Pré-requisitos

O modo Hostoo não precisa de Redis, Reverb, Nginx nem worker permanente. Sobram
**PHP com as extensões certas** e **MySQL**.

### 2.1 Onde rodar o PHP

| Caminho | Comando | Quando usar |
|---|---|---|
| **A — container `app`** (verificado) | `docker compose run --rm --no-deps app php artisan ...` | PHP 8.4 com `pdo_mysql`, `gd`, `intl`, `bcmath` já instalados |
| B — PHP do host | `php artisan ...` | só se o PHP do host tiver essas extensões |

**Versão exigida: PHP 8.4** — mínimo **8.4.1**, porque os componentes do Symfony
usados pelo Laravel 13 exigem essa faixa. Confira o caminho A:

```bash
docker compose run --rm --no-deps app php -v    # PHP 8.4.x
docker compose run --rm --no-deps app php -r 'echo PHP_VERSION_ID, PHP_EOL;'
```

Abaixo de 8.4.1 o `vendor/composer/platform_check.php` derruba a requisição.
Testado aqui rodando o mesmo arquivo sob `php:8.3-cli-alpine`:

```
Fatal error: Uncaught RuntimeException: Composer detected issues in your platform:
Your Composer dependencies require a PHP version ">= 8.4.1". You are running 8.3.36.
```


Nesta máquina o PHP do host é **8.5.4 sem `pdo_mysql`, `gd`, `intl` e `bcmath`**
(apenas `fileinfo`, `mbstring`, `pcntl`, `zip`), então o caminho A é o único que
funciona hoje. Para usar o caminho B:

```bash
sudo apt install php8.5-mysql php8.5-gd php8.5-intl php8.5-bcmath
php -m | grep -E 'pdo_mysql|gd|intl|bcmath'   # precisa listar as quatro
```

> Atenção: 8.5 satisfaz o `^8.4` do `composer.json`, mas **não é** a versão do
> Hostoo (D-07: o painel precisa entregar 8.4.x, mínimo 8.4.1 — seção 1.1 de
> [ARQUITETURA_PRODUCAO_HOSTOO.md](ARQUITETURA_PRODUCAO_HOSTOO.md)). O caminho B
> serve para descobrir dependências de extensão, não como paridade final de
> versão.

### 2.2 Banco e Node

```bash
docker compose up -d mysql      # único serviço de infraestrutura necessário
node -v                          # v22 — usado só por `npm run build`
```

O `.env` do Docker (`arcana_tarot`, Redis, Reverb) **não é alterado** em nenhum
passo. O modo Hostoo vive em um arquivo à parte, `.env.hostoo-local` (ignorado
pelo Git).

## 3. Passo 1 — criar o perfil de ambiente

```bash
cp .env.hostoo-local.example .env.hostoo-local
```

O arquivo já traz o modo Hostoo pronto: `BROADCAST_CONNECTION=log`,
`CONSULTATION_WEBSOCKET_ENABLED=false`, `CACHE_STORE=database`,
`QUEUE_CONNECTION=database`, `SESSION_DRIVER=database`, `FILESYSTEM_DISK=local`.

Diferenças propositais em relação ao `.env.hostoo.example` (produção):

| Chave | Produção | Local | Motivo |
|---|---|---|---|
| `APP_ENV` | `production` | `hostoo-local` | precisa casar com o sufixo do arquivo (§3.1) |
| `APP_DEBUG` | `false` | `true` | stack trace durante o teste |
| `APP_URL` | `https://dominio` | `http://127.0.0.1:8000` | porta do servidor embutido |
| `SESSION_SECURE_COOKIE` | `true` | `false` | em `http://` o navegador descarta o cookie e o login "não persiste" |
| `MAIL_MAILER` | `smtp` | `log` | evita enviar e-mail real (D-06 segue pendente) |
| `PAYMENT_PROVIDER` | `infinitepay` | `pix_sandbox` | exercita a carteira sem credenciais nem URL pública |

```bash
docker compose run --rm --no-deps app php artisan key:generate --env=hostoo-local
```

### 3.1 O nome do ambiente tem de casar com o sufixo do arquivo

`--env=hostoo-local` carrega **`.env.hostoo-local`**, e o servidor embutido repassa
`APP_ENV` para o processo filho, que então procura `.env.{APP_ENV}`. Se o arquivo
declarar `APP_ENV=local`, o filho cai no `.env` do Docker: o primeiro sintoma
observado foi a home em **500** com
`Table 'arcana_tarot.attendant_modality_prices' doesn't exist` — o servidor estava
no banco de desenvolvimento, não no banco do teste.

## 4. Passo 2 — banco separado e schema

O usuário `arcana` recebe `GRANT` apenas no banco do `.env`; crie o banco do teste
e libere o acesso:

```bash
docker compose exec -T mysql mysql -uroot -parcana_root_secret -e "
  CREATE DATABASE IF NOT EXISTS arcana_hostoo
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  GRANT ALL PRIVILEGES ON arcana_hostoo.* TO 'arcana'@'%';
  FLUSH PRIVILEGES;"
```

```bash
docker compose run --rm --no-deps app php artisan migrate --force --env=hostoo-local
docker compose run --rm --no-deps app php artisan migrate:status --env=hostoo-local | tail -5
```

As tabelas `sessions`, `cache`, `jobs` e `failed_jobs` vêm das migrations padrão
— são elas que substituem o Redis.

## 5. Passo 3 — dados mínimos

```bash
# Catálogo de tarô (idempotente, preserva edições administrativas)
docker compose run --rm --no-deps app \
  php artisan arcana:import-tarot-catalog --env=hostoo-local

# Administração
docker compose run --rm --no-deps app php artisan arcana:create-admin \
  --name='Admin Hostoo' --email=admin@hostoo.test --password='SenhaForte123!' \
  --env=hostoo-local

# Atendente publicado, com tarifas por modalidade e taxa de comissão
docker compose run --rm --no-deps app php artisan arcana:create-attendant \
  --name='Atendente Hostoo' --email=atendente@hostoo.test \
  --password='SenhaForte123!' --display-name='Atendente Teste' \
  --commission-rate=30 --price-text=500 --price-audio=700 --price-video=900 \
  --publish --env=hostoo-local
```

Saída conferida do catálogo: `2 baralho(s)` e `114 carta(s)`.

> As imagens do catálogo vivem em `storage/app/private` e são compartilhadas entre
> os perfis (no Hostoo elas fazem parte do pacote enviado à conta). O importador
> só grava o que falta e nunca sobrescreve imagem editada pela administração.

Para o fluxo de cliente, cadastre-se pela tela `/register` do próprio servidor de
teste — é o mesmo caminho da produção.

## 6. Passo 4 — subir como no Hostoo (servidor + cron emulado)

### 6.1 Um comando só: servidor embutido + as duas linhas de cron

O `--no-deps` impede o Compose de subir Redis/Reverb junto com o `app`:

```bash
docker compose stop nginx redis reverb queue   # nada disso existe no Hostoo

docker compose run --rm --no-deps -p 8000:8000 app sh -c '
  php artisan serve --env=hostoo-local --host=0.0.0.0 --port=8000 &
  sleep 4
  while true; do
    php artisan schedule:run --env=hostoo-local
    php artisan queue:work --env=hostoo-local --stop-when-empty --max-time=50 --tries=3
    sleep 60
  done'
```

Acesse `http://127.0.0.1:8000`. `Ctrl+C` encerra servidor e cron juntos (o
container é `--rm`). O laço replica exatamente as duas linhas do crontab do
Hostoo:

```
* * * * * /caminho/absoluto/php /home/conta/arcana/artisan schedule:run
* * * * * /caminho/absoluto/php /home/conta/arcana/artisan queue:work --stop-when-empty --max-time=50 --tries=3
```

Saída conferida no arranque: `schedule:run` executa os quatro comandos
(`expire-requests`, `interrupt-disconnected`, `charge-minutes`,
`close-stale-calls`) e em seguida `Worker STOPPED  Queue empty`.

Se a porta 8000 estiver ocupada pelo Nginx, use outra nas duas pontas:
`-p 8081:8081` e `--port=8081`.

### 6.2 Por que não usar o worker permanente do Docker

`docker compose up` sobe o serviço `queue` com `queue:work --max-time=3600` e o
`reverb`. **Nenhum dos dois existe no Hostoo**, e mantê-los ligados esconde
justamente o erro mais provável do deploy: cron não configurado, com a fila
andando por outro worker. O teste só é honesto com `redis`, `reverb`, `queue` e
`nginx` parados, como no comando acima.

`php artisan schedule:work` também não entra: ele é um processo em memória que
dispara o scheduler a cada minuto. No Hostoo quem faz isso é o cron, uma
execução de `schedule:run` por minuto — e `--stop-when-empty --max-time=50`
garante que o `queue:work` termine antes do próximo minuto.

### 6.3 Assets compilados

```bash
rm -f public/hot          # HMR de um `npm run dev` esquecido aponta para :5173
npm run build
```

Conferido: a home passa a referenciar `/build/assets/app-*.css` e `app-*.js`.
Lembre que `public/build` está no `.gitignore`: no Hostoo ele precisa ser gerado lá
(`npm run build`) ou enviado por `rsync`/`git add -f` — ver §9.8 de
[ARQUITETURA_PRODUCAO_HOSTOO.md](ARQUITETURA_PRODUCAO_HOSTOO.md).

## 7. Checklist de fumaça

### 7.1 Roteiro

| # | Verificação | Como | Esperado |
|---|---|---|---|
| 1 | Modo polling publicado | DevTools → Elements em `/attendant` e no chat | `data-realtime-mode="poll"` |
| 2 | Nenhum WebSocket | DevTools → Network → filtro `WS` | lista vazia; nenhuma chamada a `:8080` |
| 3 | Intervalos de leitura | atributos do bloco + aba Network | chat `2000` ms (`10000` oculto), painel `3000` ms (`15000` oculto), backoff até `30000` |
| 4 | Teto de leituras | contar requisições em 1 min | ≤ 90/min no chat, ≤ 60/min no painel |
| 5 | Sessão no MySQL | duas abas logadas; `SELECT COUNT(*) FROM sessions` | cresce; sobrevive a recarregar e a reiniciar o servidor |
| 6 | Fila | criar uma solicitação e ver `SELECT COUNT(*) FROM jobs` | > 0 logo após o evento e 0 depois do `queue:work` |
| 7 | Dependência do cron | parar o laço do §6.1 e criar outra solicitação | avisos **param** de chegar (prova de que nada anda sem cron) |
| 8 | Agendador | `php artisan schedule:list --env=hostoo-local` | quatro comandos `everyMinute` |
| 9 | Cobrança por minuto | consulta ativa + `consultations:charge-minutes` | saldo/créditos caem 1 minuto por execução; sem saldo, a consulta encerra |
| 10 | Expiração de pedido | abrir pedido e rodar `consultations:expire-requests` após o TTL | pedido sai da fila do atendente (TTL 10 min) |
| 11 | Chamada sem resposta | iniciar áudio/vídeo e rodar `consultations:close-stale-calls` | encerra após 90 s de toque |
| 12 | Upload | carta de 5 MB e som de 1 MB | aceitos; acima disso, erro de validação da aplicação (não 413 do PHP) |
| 13 | Carteira | recarga + `pix:sandbox-simulate` (§7.3) | créditos creditados uma única vez (idempotência) |
| 14 | Healthcheck | `curl -o /dev/null -w '%{http_code}' http://127.0.0.1:8000/up` | `200` |
| 15 | Assets compilados | view-source da home | `/build/assets/...`, nada de `localhost:5173` |
| 16 | Versão do PHP | `docker compose ... app php -v` (no Hostoo, `php -v` no cron do painel) | `8.4.x`; abaixo de `8.4.1` o app responde 500 com `Composer detected issues` |

Os quatro comandos do cron podem ser disparados na mão, sem esperar o minuto:

```bash
for c in consultations:expire-requests consultations:interrupt-disconnected \
         consultations:charge-minutes consultations:close-stale-calls; do
  docker compose run --rm --no-deps app php artisan "$c" --env=hostoo-local
done
```

Com o banco recém-criado a saída é:

```
Solicitações expiradas: 0.
Consultas interrompidas: 0.
Cobrança por minuto: 0 minuto(s) cobrado(s), 0 consulta(s) encerrada(s), 0 falha(s).
Chamadas sem resposta encerradas: 0.
```

### 7.2 Modo estrito (guardas de produção)

`APP_ENV=hostoo-local` não ativa os guardas que só valem em `production`
(bloqueio do seeder de demonstração, páginas de erro sem stack trace). Para
validá-los, crie um terceiro perfil — `.env.production` já é ignorado pelo Git —
com o mesmo conteúdo e três ajustes:

```bash
cp .env.hostoo-local .env.production
# edite: APP_ENV=production, APP_DEBUG=false, SESSION_SECURE_COOKIE=true
docker compose run --rm --no-deps app php artisan key:generate --env=production
docker compose run --rm --no-deps app php artisan db:seed --env=production   # deve recusar o demo
```

Atenção ao `SESSION_SECURE_COOKIE=true`: em `http://` o login deixa de funcionar —
é o mesmo comportamento que aparece no Hostoo quando o TLS não é detectado (§8).

### 7.3 Recarga de créditos sem provedor real

```bash
docker compose run --rm --no-deps app php artisan pix:sandbox-simulate {payment} approved --env=hostoo-local
docker compose run --rm --no-deps app php artisan pix:sandbox-simulate {payment} approved --env=hostoo-local
```

A segunda chamada deve ser ignorada (webhook idempotente). `declined` e `expired`
também são aceitos. Este comando só funciona com `PAYMENT_PROVIDER=pix_sandbox`,
que é o padrão do perfil local.

## 8. O que nenhum teste local cobre

| Item | Risco se falhar | Como tratar |
|---|---|---|
| TLS no proxy do provedor (`X-Forwarded-Proto`) | URLs `http://` e mixed content; cookie `secure` recusado | §9 |
| `.htaccess` / LiteSpeed | 404 em todas as rotas, header `Authorization` perdido | só no servidor |
| Docroot fora de `public_html` (D-03) | `@vite`, `asset()`, cookie `SESSION_PATH` errados | só no servidor |
| PHP do **cron** ≠ PHP do site | cron "não faz nada" em silêncio | caminho absoluto do binário no crontab |
| `disable_functions`, `max_execution_time` | `queue:work`/`schedule:run` morrem no meio | checklist do painel |
| Upload máximo do provedor | 413 em cartas de 5 MB | testar com arquivo grande no Hostoo |
| SMTP real (D-06) | e-mails não saem | teste real no servidor |
| MariaDB 10.x × MySQL 8.4 | `sql_mode`, `ONLY_FULL_GROUP_BY`, colação | `SELECT VERSION(), @@sql_mode` no painel |
| Concorrência | `serve` é single-thread (FPM atende em paralelo) | abra 2–3 abas; se precisar, `PHP_CLI_SERVER_WORKERS=4` **com** `--no-reload` |

Sobre concorrência: sem `--no-reload`, o `serve` só repassa ao processo filho uma
lista branca de variáveis (`APP_ENV`, `PATH`, `PHP_IDE_CONFIG`, …). Exportar
`QUEUE_CONNECTION` ou `PHP_CLI_SERVER_WORKERS` no shell **não** tem efeito — é por
isso que o perfil vive em um arquivo e não em `export`.

## 9. Pendência conhecida: `TRUSTED_PROXIES` não é lido do `.env`

`bootstrap/app.php` chama `env('TRUSTED_PROXIES')` dentro de um closure executado
quando o kernel HTTP é resolvido, **antes** do bootstrap `LoadEnvironmentVariables`.
Medido neste repositório, com reflexão em `TrustProxies::$alwaysTrustProxies`:

| Origem do valor | Resultado |
|---|---|
| `TRUSTED_PROXIES=10.9.9.9` no arquivo `.env` | `NULL` — ignorado |
| `TRUSTED_PROXIES=10.9.9.9` no ambiente do processo | `'10.9.9.9'` |

Agravante de produção: com `config:cache`, o Laravel **nem carrega o `.env`**
(`LoadEnvironmentVariables` retorna cedo quando `configurationIsCached()`), então
qualquer `env()` fora de `config/` vale `null`.

Consequência no Hostoo: se o TLS termina no proxy do provedor e o Apache/LiteSpeed
não marca `HTTPS=on`, o `X-Forwarded-Proto: https` é ignorado — `route()` e
`asset()` saem em `http://` (mixed content) e `$request->isSecure()` fica falso.

Opções (decisão pendente, nenhuma aplicada):

1. `->trustProxies(at: '*')` fixo no `bootstrap/app.php` — uma linha, como nos
   exemplos da documentação do Laravel; efeito colateral: `X-Forwarded-For` passa a
   ser aceito de qualquer origem.
2. Mover para um service provider que rode depois de env+config
   (`TrustProxies::at(config('app.trusted_proxies'))`) — mais correto, mais código.
3. `URL::forceScheme('https')` quando `APP_ENV=production` — resolve as URLs
   geradas, não `isSecure()`.

Enquanto a decisão não sai, o valor pode ser injetado pelo servidor, sem tocar no
código (funciona com ou sem `config:cache`):

```apache
# public/.htaccess (o Apache entrega via SetEnv em $_SERVER, que o env() lê)
<IfModule mod_env.c>
    SetEnv TRUSTED_PROXIES "*"
</IfModule>
```

Validação no Hostoo, depois do deploy:

```bash
curl -s -H 'X-Forwarded-Proto: https' https://seu-dominio/ | grep -o 'https\?://[^"]*' | head
```

Se os assets saírem em `http://`, o problema se confirmou.

## 10. Voltando ao ambiente de desenvolvimento

```bash
docker compose start nginx redis reverb queue
docker compose run --rm --no-deps app php artisan optimize:clear   # se usou config:cache
```

O perfil do teste não é apagado: `.env.hostoo-local` continua no disco (ignorado
pelo Git) e pode ser reutilizado. Para descartar o banco de teste:

```bash
docker compose exec -T mysql mysql -uroot -parcana_root_secret \
  -e "DROP DATABASE arcana_hostoo;"
```

A suíte automatizada é independente deste perfil (o `phpunit.xml` fixa conexão
própria) e continua rodando no container normal:

```bash
docker compose exec -T app php artisan test --compact
```

## 11. Referências

- [ARQUITETURA_PRODUCAO_HOSTOO.md](ARQUITETURA_PRODUCAO_HOSTOO.md) — arquitetura,
  crons, `.env` de produção e decisões pendentes do painel.
- [DEPLOYMENT.md](DEPLOYMENT.md) — runbook Docker, migrations, backups.
- [REALTIME.md](REALTIME.md) — canais, eventos e o caminho com Reverb.
- [CONSULTATION_STATE_MACHINE.md](CONSULTATION_STATE_MACHINE.md) — rotinas
  automáticas acionadas pelo cron.
- `.env.hostoo-local.example` — perfil usado neste documento.




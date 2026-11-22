# Publicação automatizada (`deploy.sh`)

Script único, na raiz do repositório, que publica uma nova versão em **uma**
instância da VPS (`/var/www/projetos/voz-arcana` ou `/var/www/projetos/astros21`).
Ele lê a identidade da instância do `.env` e do `docker-compose.prod.yml`, então
o mesmo arquivo serve para os dois ambientes sem edição.

## Uso rápido

```bash
cd /var/www/projetos/astros21      # ou voz-arcana
chmod +x deploy.sh                 # só na primeira vez
./deploy.sh                        # pede confirmação antes de publicar
```

Sem interação (cron, CI, script de operação):

```bash
./deploy.sh --yes
```

Ajuda:

```bash
./deploy.sh --help
```

## O que ele faz, em ordem

| Etapa | Ações |
| --- | --- |
| 1. Validações | git e `.git` na raiz, `.env` legível, Docker/Compose v2, `docker-compose.prod.yml` válido (`docker compose config`), `APP_IMAGE`/`APP_SLUG`, detecção dos serviços da instância, espaço em disco |
| 2. Git | `git fetch`, resumo do que chega, confirmação e `git pull --ff-only`. Alterações locais em arquivos rastreados **abortam** o deploy |
| 3. Build | `docker compose build web` (a única imagem da instância), **com cache**; a imagem atual é preservada como `<APP_IMAGE>-anterior` |
| 4. Artisan | `optimize:clear` + `migrate --force` + `optimize` **uma única vez**, num container descartável (`compose run --rm`). Os containers de produção seguem no ar |
| 5. Containers | `compose up -d --no-deps --no-build` apenas nos serviços cuja imagem é a `APP_IMAGE` (web, queue, reverb); confere que cada container está na imagem recém-construída |
| 6. Healthcheck | espera `healthy` (web, reverb) e execução estável sem reinícios (queue, que não declara healthcheck); em falha, mostra os últimos logs e sai com código 1 |
| 7. Relatório | ambiente, diretório, branch, commit anterior/novo, imagem, serviços, migrations, healthchecks, duração, containers e o comando de rollback |

## Isolamento (a VPS hospeda outros projetos)

O script **nunca**:

- executa `docker compose down`, `down -v`, `docker system prune`,
  `docker volume prune`, `docker image prune` ou `docker rm`;
- reinicia MySQL, Redis ou o proxy compartilhado (`deploy/docker-compose.infra.yml`
  não é tocado; a rede `shared-net` só é usada);
- altera o `.env` (ele é lido por `grep`, nunca executado com `source`);
- descarta trabalho local (`reset --hard`, `clean`, merge automático);
- toca em containers de outra instância: só entram no `up` os serviços cujo
  `image:` resolve para a `APP_IMAGE` desta pasta.

Duas publicações simultâneas são bloqueadas por lock em `${TMPDIR:-/tmp}`:
um lock por instância e um lock global de build (evita dois builds ao mesmo
tempo na VPS de 2 GB). Os locks são liberados automaticamente quando o processo
termina, inclusive em caso de erro ou Ctrl+C.

## Variáveis opcionais

| Variável | Padrão | Para que serve |
| --- | --- | --- |
| `DEPLOY_HEALTH_TIMEOUT` | `240` | segundos de espera pelo healthcheck de cada serviço |
| `DEPLOY_HEALTH_POLL` | `5` | intervalo entre verificações |
| `DEPLOY_HEALTH_SETTLE` | `20` | tempo mínimo que um serviço sem healthcheck precisa ficar de pé, sem reinícios |
| `DEPLOY_BUILD_LOCK_WAIT` | `1800` | quanto esperar pelo lock global de build antes de desistir |
| `DEPLOY_LOG_TAIL` | `80` | linhas de log exibidas por serviço quando algo falha |

Exemplo: `DEPLOY_HEALTH_TIMEOUT=420 ./deploy.sh`.

## Como interpretar erros

A mensagem sempre traz a etapa, a linha e o comando:

```
ERRO  deploy interrompido na Etapa 4/7 — migrações e caches (linha 690, código 1)
ERRO  comando que falhou: compose run --rm --no-deps -e SKIP_OPTIMIZE=1 ...
```

Códigos de saída: `0` concluído (ou cancelado por você antes de publicar),
`1` falha em alguma etapa, `2` uso incorreto.

| Situação | O que fazer |
| --- | --- |
| `.env` não encontrado / ilegível | criar a partir de `deploy/env/production.env.example` e `chmod 600 .env` |
| `APP_IMAGE` não é usada por nenhum serviço | conferir `APP_IMAGE` no `.env` (uma tag por instância, ex.: `arcana-tarot/app:astros21`) |
| "há alterações locais em arquivos rastreados" | `git stash push -m 'antes do deploy'` (ou commit) e rodar de novo — nada foi alterado |
| `git pull --ff-only` falhou | o histórico local divergiu do remoto; resolver manualmente, o script não faz merge |
| "outro deploy em execução" | esperar a execução atual terminar (o lock é liberado no fim) |
| build falhou | containers antigos continuam no ar; ver o log do deploy e corrigir o código |
| migrations falharam | nenhum container foi recriado; corrigir a migration e rodar de novo |
| healthcheck falhou | os logs dos serviços são impressos; rollback abaixo |

## Logs

- Arquivo por execução: `storage/logs/deploy-AAAAmmdd-HHMMSS.log` (o caminho é
  impresso no início e no relatório final).
- Containers: `docker compose -f docker-compose.prod.yml logs -f web queue reverb`
  (acrescente `--tail=100` para ver só o fim).

## Rollback

A imagem anterior fica marcada como `<APP_IMAGE>-anterior` antes do build:

```bash
cd /var/www/projetos/astros21
docker tag arcana-tarot/app:astros21-anterior arcana-tarot/app:astros21
docker compose -f docker-compose.prod.yml up -d --no-deps --force-recreate web queue reverb
```

Migrations não são desfeitas por rollback de imagem: mantenha o padrão de
migrations compatíveis com a versão anterior (coluna nova nullable, preencher
depois, remover só na versão seguinte) — o mesmo já descrito em
[`deploy/DEPLOY.md`](../deploy/DEPLOY.md).

## Limitações conhecidas

- O build roda na própria VPS (para reaproveitar o cache do Docker): durante
  alguns minutos o uso de CPU/RAM sobe. O lock global evita dois builds juntos.
- Não há backup automático do banco antes das migrations; para mudanças
  destrutivas, rode o backup manual descrito em `deploy/DEPLOY.md`.
- O script não cria `shared-net` nem a infraestrutura compartilhada; ele assume
  que a instância já subiu ao menos uma vez conforme `deploy/DEPLOY.md`.

# Deploy em produção — VPS de 2 GB com duas instâncias

Este documento descreve a instalação **do zero** de duas aplicações Laravel
independentes (`astros21` e `voz-arcana`) no mesmo VPS, compartilhando banco,
Redis e proxy reverso.

```
                    internet :80 / :443
                            │
                  ┌─────────▼──────────┐
                  │   shared-proxy     │  nginx + TLS (48 MB)
                  │  (deploy/nginx)    │  roteia por domínio
                  └───┬────────────┬───┘
        astros21.dom  │            │  voz-arcana.dom
        ┌─────────────▼──┐      ┌──▼──────────────┐
        │ astros21-web   │      │ voz-arcana-web  │  nginx + php-fpm + cron
        │ :80 (interno)  │      │ :80 (interno)   │  (220 MB cada)
        ├────────────────┤      ├─────────────────┤
        │ astros21-queue │      │ voz-arcana-queue│  queue:work (150 MB)
        │ astros21-reverb│      │ voz-arcana-reverb│ reverb:start (150 MB)
        └───┬────────┬───┘      └───┬─────────┬───┘
            │        │              │         │
     ┌──────▼────────▼──────────────▼─────────▼──────┐
     │  shared-mysql (MariaDB 10.11, 350 MB)         │
     │  shared-redis (Redis 7, 64 MB)                │
     │  rede docker `shared-net`                     │
     └───────────────────────────────────────────────┘
```

* Um arquivo por instância para a aplicação: `docker-compose.prod.yml`.
* Um arquivo para a infraestrutura compartilhada:
  `deploy/docker-compose.infra.yml`.
* Cada instância tem o seu `.env`, a sua imagem Docker e o seu volume
  `storage/`. Nada de banco/Redis/proxy dentro delas.

## Orçamento de memória

| Container            | Limite     | Consumo esperado em repouso |
| -------------------- | ---------- | --------------------------- |
| `shared-mysql`       | 350 MB     | ~50 MB                      |
| `shared-redis`       | 64 MB      | ~5 MB                       |
| `shared-proxy`       | 48 MB      | ~12 MB                      |
| `<slug>-web` (×2)    | 220 MB     | ~35 MB (fpm ocioso) + nginx |
| `<slug>-queue` (×2)  | 150 MB     | ~30 MB                      |
| `<slug>-reverb` (×2) | 150 MB     | ~40 MB                      |
| **Total de limites** | **1,5 GB** | ~250 MB                     |

Os limites são tetos, não reservas: a soma (1,5 GB) deixa ~500 MB para o
sistema operacional, `sshd` e picos. Se o VPS tiver 2 GB **sem swap**, crie
2 GB de swap (seção "Pré-requisitos") — sem swap o kernel mata processos em vez
de paginar, e o `web` é sempre a primeira vítima.

Onde mexer se faltar memória, em ordem de menor impacto:

1. `docker/php/www.conf` → `pm.max_children` (hoje 5) **junto** com o
   `mem_limit` de `web` em `docker-compose.prod.yml`.
2. `deploy/mysql/my.cnf` → `innodb_buffer_pool_size` (hoje 128M) e o
   `mem_limit` de `shared-mysql`.
3. `docker/php/production.ini` → `memory_limit` (hoje 128M).
4. Desligar áudio/vídeo (`CONSULTATION_AUDIO_ENABLED=false`,
   `CONSULTATION_VIDEO_ENABLED=false`) se o Reverb estiver no limite.

Nunca suba `memory_limit` do PHP acima de ~160M sem subir o `mem_limit` do
container: o Linux mata o processo **sem** mensagem do PHP, e o sintoma que
aparece para o usuário é "502 Bad Gateway" sem nada em `storage/logs`.

## Pré-requisitos no servidor

```bash
# Docker + plugin compose (Ubuntu 22.04/24.04)
curl -fsSL https://get.docker.com | sh
docker --version && docker compose version        # >= 2.20

# Swap de 2 GB (obrigatório em VPS de 2 GB)
fallocate -l 2G /swapfile && chmod 600 /swapfile
mkswap /swapfile && swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
sysctl -w vm.swappiness=10
echo 'vm.swappiness=10' >> /etc/sysctl.conf

# Firewall: só 22, 80 e 443
ufw allow 22/tcp && ufw allow 80/tcp && ufw allow 443/tcp && ufw enable

# Fuso horário (o MariaDB usa para `NOW()`, e as rotinas de cobrança do
# agendador comparam horários)
timedatectl set-timezone America/Sao_Paulo
```

DNS: crie os registros `A` (e `AAAA`, se houver IPv6) de cada domínio
apontando para o IP do VPS **antes** do passo de TLS. Se a aplicação for
acessada por `www`, crie também o `CNAME` e repita o domínio nos arquivos de
`deploy/nginx/conf.d/`.

O espaço em disco importa: cada imagem de aplicação tem ~340 MB (PHP +
extensões + nginx + vendor + bundle). Duas instâncias + banco + backups pedem
pelo menos **20 GB**. As imagens antigas de cada deploy são removidas com
`docker image prune -f`.

## Passo 1 — Infraestrutura compartilhada

`deploy/` deve existir em **um** lugar do servidor, por exemplo `/srv/infra`
(copie a pasta deste repositório; ela não pertence a nenhuma das instâncias).

```bash
mkdir -p /srv/infra && cd /srv/infra
# copie para cá o conteúdo de deploy/ deste repositório

docker network create shared-net       # uma única vez no servidor

cp .env.example .env
nano .env                              # senhas: openssl rand -base64 24
```

Regras do `deploy/.env`:

* senhas **diferentes** para cada banco; o root nunca vai para a aplicação;
* sem `'`, `"` ou `$` nas senhas (o script de init monta SQL e o Compose
  interpola `$`);
* `ASTROS21_DB_PASSWORD` e `VOZ_ARCANA_DB_PASSWORD` são copiados para o
  `DB_PASSWORD` de cada instância no passo 3.

Antes de subir, desative temporariamente os roteamentos por domínio: eles
apontam para certificados que ainda não existem, e o nginx se recusa a iniciar
sem os arquivos.

```bash
mkdir -p nginx/letsencrypt nginx/acme
mv nginx/conf.d/astros21.conf    nginx/conf.d/astros21.conf.disabled
mv nginx/conf.d/voz-arcana.conf  nginx/conf.d/voz-arcana.conf.disabled

docker compose -f docker-compose.infra.yml up -d
docker compose -f docker-compose.infra.yml ps        # os 3 devem ficar healthy
```

Neste momento o MariaDB cria os dois bancos e usuários
(`mysql/init/01-create-databases.sh` roda **apenas** com o volume vazio).
Conferindo:

```bash
docker exec -it shared-mysql mysql -uroot -p -e 'SHOW DATABASES'
docker exec -it shared-mysql mysql -uroot -p -e "SHOW GRANTS FOR 'astros21'@'%'"
```

> Se você errar uma senha do `deploy/.env` **depois** do primeiro boot, o
> script de init não roda de novo: corrija com
> `docker exec -it shared-mysql mysql -uroot -p -e "ALTER USER 'astros21'@'%' IDENTIFIED BY 'nova';"`.
> Para recriar do zero: `docker compose -f docker-compose.infra.yml down -v`
> (**apaga os dados**).

## Passo 2 — TLS (certificados)

Os certificados ficam no host, em `deploy/nginx/letsencrypt/` (layout do
certbot: `live/<dominio>/{fullchain.pem,privkey.pem}`), e são montados
somente-leitura no proxy. Renovação não precisa derrubar nada.

Com os arquivos de domínio ainda desativados (passo 1), o proxy responde na
porta 80 e serve o webroot do desafio em `/var/www/acme` — crie os
certificados um domínio por vez:

```bash
cd /srv/infra

docker run --rm \
  -v "$PWD/nginx/letsencrypt:/etc/letsencrypt" \
  -v "$PWD/nginx/acme:/var/www/acme" \
  certbot/certbot certonly --webroot -w /var/www/acme \
  -d astros21.seudominio.com \
  --email admin@seudominio.com --agree-tos --no-eff-email

# repita trocando o -d para voz-arcana.seudominio.com
```

Depois reative o roteamento e recarregue:

```bash
sed -i 's/astros21\.seudominio\.com/SEU-DOMINIO-REAL/g'  \
    nginx/conf.d/astros21.conf.disabled
sed -i 's/voz-arcana\.seudominio\.com/SEU-DOMINIO-REAL/g' \
    nginx/conf.d/voz-arcana.conf.disabled

mv nginx/conf.d/astros21.conf.disabled   nginx/conf.d/astros21.conf
mv nginx/conf.d/voz-arcana.conf.disabled nginx/conf.d/voz-arcana.conf

docker exec shared-proxy nginx -t && docker exec shared-proxy nginx -s reload
```

Renovação automática (os certificados do Let's Encrypt duram 90 dias; renove
com 30 de folga). O `--deploy-hook` só recarrega o nginx, sem downtime:

```bash
cat >/etc/cron.d/certbot-arcana <<'EOF'
0 3 * * * root docker run --rm \
  -v /srv/infra/nginx/letsencrypt:/etc/letsencrypt \
  -v /srv/infra/nginx/acme:/var/www/acme \
  certbot/certbot renew --webroot -w /var/www/acme --quiet \
  && docker exec shared-proxy nginx -s reload
EOF
```

Enquanto os domínios não apontarem para o VPS, o `renew --webroot` falha
(nenhum problema: o certificado continua válido até expirar).

## Passo 3 — Instância da aplicação (repetir para cada site)

Os caminhos usados neste exemplo são `/srv/astros21` e `/srv/voz-arcana`.

```bash
git clone <repositorio> /srv/astros21 && cd /srv/astros21

cp deploy/env/production.env.example .env   # modelo de produção (deste repositório)
nano .env        # APP_SLUG, APP_IMAGE, APP_NAME, APP_URL, banco, Redis, Reverb, SMTP

# APP_KEY: o Laravel espera "base64:" seguido de 32 bytes em base64
echo "base64:$(openssl rand -base64 32)"   # cole em APP_KEY=

# Dono dos arquivos = uid 1000 (appuser dentro do container)
mkdir -p storage bootstrap/cache
chown -R 1000:1000 storage bootstrap/cache
```

O `.env` é lido **duas vezes**: pelo Laravel (montado read-only no container) e
pelo Compose (interpolação de `${...}`). Consequências práticas:

* `APP_SLUG` define os nomes dos containers (`astros21-web`,
  `astros21-queue`, `astros21-reverb`) — é o nome que o proxy procura;
* `APP_IMAGE` é a tag da imagem daquela instância (as `VITE_*` são embutidas no
  bundle pelo Vite, então cada site tem a sua imagem);
* valores com `$` precisam ser escritos como `$$` ou entre aspas simples, senão
  o Compose os interpreta (ex.: `INFINITEPAY_HANDLE`).

Build e subida:

```bash
docker compose -f docker-compose.prod.yml build       # ~3-5 min na primeira vez
docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml ps          # web deve ficar healthy
docker compose -f docker-compose.prod.yml logs -f web # acompanhe o boot
```

Primeira instalação — banco, catálogo de cartas e administrador:

```bash
DC="docker compose -f docker-compose.prod.yml"

$DC run --rm web php artisan migrate --force
$DC run --rm --user appuser web php artisan arcana:import-tarot-catalog
$DC run --rm -it web php artisan arcana:create-admin
```

* `arcana:import-tarot-catalog` copia as frentes de carta de
  `database/seeders/assets/tarot` (dentro da imagem) para
  `storage/app/private/tarot/cards` — sem isso o site sobe sem imagens de
  baralho;
* `--user appuser` só é necessário em comandos que **gravam** em `storage/`
  (o container inicia como root para poder abrir a porta 80 e ajustar donos);
* migrations rodam com `--force` porque `APP_ENV=production`.

> `storage/` é um volume do host e começa vazio: guarde o backup dele junto com
> o do banco. `public/storage` é um symlink criado na imagem, apontando para
> `../storage/app/public` — uploads de perfil/logo continuam funcionando.

## Passo 4 — Verificação

```bash
DC="docker compose -f docker-compose.prod.yml"

curl -s -o /dev/null -w '%{http_code}\n' https://SEU-DOMINIO/up   # 200
$DC exec web wget -q -O - http://127.0.0.1/fpm-status | head -12   # pool: www / ondemand
$DC logs --tail=30 queue                                           # sem erro de Redis
$DC exec web supervisorctl status                                  # 3x RUNNING

# WebSocket (Reverb): no navegador, aba Network → WS, deve aparecer
#   wss://SEU-DOMINIO/app/<REVERB_APP_KEY>  com status 101
```

No `docker stats` cada container deve ficar bem abaixo do limite (web ~90 MB,
queue ~40 MB, reverb ~45 MB em repouso). Se o `web` já estiver perto de 220 MB
sem tráfego, veja "Diagnóstico" antes de divulgar o site.

## Passo 5 — Atualizações e rollback

Dentro da pasta da instância:

```bash
DC="docker compose -f docker-compose.prod.yml"

git pull                                             # código novo
$DC build                                            # imagem nova
$DC run --rm web php artisan migrate --force         # se houver migration nova
$DC up -d                                            # recria só o que mudou
$DC exec web supervisorctl restart php-fpm           # descarta OPcache antigo
$DC exec queue php artisan queue:restart             # worker recarrega o código
docker image prune -f                                # libera disco
```

Ordem importa: **build antes do `up`** (o `up -d` sobe a imagem já construída e
só recria os containers cuja imagem/configuração mudou).

Rollback: as imagens são etiquetadas por instância e por data. Antes de
atualizar, guarde a atual:

```bash
docker tag arcana-tarot/app:astros21 arcana-tarot/app:astros21-anterior
# se der errado:
docker tag arcana-tarot/app:astros21-anterior arcana-tarot/app:astros21
$DC up -d --force-recreate
```

Migration é a única coisa que o rollback de imagem não desfaz: mantenha o
padrão de migrations compatíveis com a versão anterior (coluna nova nullable,
depois preencher, e só na versão seguinte remover).

Manutenção programada (o site responde 503 com a sua mensagem):

```bash
$DC exec web php artisan down --render="errors::503" --retry=60
$DC exec web php artisan up
```

Mudanças que exigem `--force-recreate` (não voltam só com `restart`): qualquer
linha do `.env`, porque o arquivo é montado no container na criação.

## Passo 6 — Backup

O que existe para salvar: o banco de cada instância e `storage/` (imagens
enviadas, catálogo importado, logs). Ambos vivem no host.

```bash
cat >/usr/local/bin/arcana-backup.sh <<'EOF'
#!/bin/bash
set -euo pipefail
DEST=/var/backups/arcana
STAMP=$(date +%F-%H%M)
mkdir -p "$DEST"

docker exec shared-mysql sh -c \
  'exec mysqldump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction \
   --quick --routines --events --databases astros21_db voz_arcana_db' \
  | gzip > "$DEST/bancos-$STAMP.sql.gz"

tar -czf "$DEST/storage-$STAMP.tar.gz" \
  -C /srv/astros21 storage -C /srv/voz-arcana storage

find "$DEST" -type f -mtime +14 -delete       # 14 dias de retenção
EOF
chmod +x /usr/local/bin/arcana-backup.sh
echo '15 3 * * * root /usr/local/bin/arcana-backup.sh' >/etc/cron.d/arcana-backup
```

Restaure com `gunzip -c bancos-*.sql.gz | docker exec -i shared-mysql mysql -uroot -p`.
Copie `$DEST` para fora do VPS (storage de objetos ou outro host) — backup no
mesmo disco não protege de perda do disco.

## Diagnóstico

| Sintoma | Onde olhar |
| ------- | ---------- |
| 502 no domínio | `docker compose -f docker-compose.prod.yml ps` (web unhealthy?), `logs web` |
| Site lentíssimo / páginas em branco | `docker stats`, `dmesg -T \| grep -i oom` |
| `OOMKilled` no `docker inspect` | `docker inspect -f '{{.State.OOMKilled}}' astros21-web` |
| Timeout no atendimento | `docker compose logs --tail=50 reverb` |
| Jobs não saem da fila | `logs queue`; `docker exec shared-redis redis-cli INFO stats \| grep evicted` |
| Mensagens em tempo real caem para polling | `logs reverb`, `curl /up`, aba WS do navegador |

Comandos que respondem quase tudo:

```bash
docker stats --no-stream --format 'table {{.Name}}\t{{.MemUsage}}\t{{.CPUPerc}}'
docker inspect -f '{{.Name}} oom={{.State.OOMKilled}} restarts={{.RestartCount}}' \
    astros21-web astros21-queue astros21-reverb
docker exec astros21-web wget -q -O - http://127.0.0.1/fpm-status | head -20
docker exec shared-redis redis-cli INFO memory | grep -E 'used_memory_human|maxmemory_human'
docker exec shared-mysql mysqladmin -uroot -p status
df -h /                          # disco cheio derruba o MariaDB primeiro
```

Casos conhecidos deste dimensionamento:

* **Redis descartando jobs.** `--maxmemory 48mb` + `allkeys-lru` foi escolhido
  para o Redis nunca recusar escrita; sob pressão, jobs da fila podem ser
  descartados. Se `INFO stats` mostrar `evicted_keys` crescendo, aumente
  `--maxmemory` (e o `mem_limit` de `shared-redis` no infra) ou reduza o cache
  de imagens (`FILESYSTEM_DISK=local` já evita cache em Redis).
* **Imagem enviada derruba o `web`.** O `ImageNormalizer` decodifica a imagem
  inteira 3 vezes; `IMAGE_MAX_PIXELS=10000000` + `IMAGE_MEMORY_LIMIT=160M`
  cabem nos 5 filhos × 220 MB. Se aparecer `OOMKilled` ao subir fotos, reduza
  `IMAGE_MAX_PIXELS` antes de qualquer outra coisa.
* **Pico do agendador.** `schedule:work` roda dentro do `web`; às vezes um
  `schedule:run` coincide com 3-4 requisições e o container toca o teto. Para
  separar de vez, use cron do host:
  `* * * * * cd /srv/astros21 && docker compose -f docker-compose.prod.yml exec -T --user appuser web php artisan schedule:run`
  e comente `[program:scheduler]` em `docker/php/supervisord.prod.conf`.

## Checklist final

- [ ] `deploy/.env` e os `.env` das instâncias com senhas geradas, `chmod 600`;
- [ ] `docker network create shared-net` feito **antes** do primeiro `up`;
- [ ] `shared-mysql`, `shared-redis` e `shared-proxy` healthy;
- [ ] certificados em `deploy/nginx/letsencrypt/live/<dominio>/` e cron de renovação;
- [ ] `APP_DEBUG=false`, `APP_ENV=production`, `APP_KEY` preenchido;
- [ ] `migrate --force` e `arcana:import-tarot-catalog` executados;
- [ ] `/up` responde 200 nos dois domínios e o WS conecta (status 101);
- [ ] `docker stats` com folga em todos os containers;
- [ ] backup diário rodando e cópia fora do VPS;
- [ ] `ufw` liberando apenas 22, 80 e 443.

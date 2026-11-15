#!/bin/sh
# =============================================================================
# Entrypoint da imagem de produção (docker/php/Dockerfile.prod)
# =============================================================================
# Roda antes do CMD/CONTAINER_ROLE de cada serviço:
#   web     -> php artisan (migrate/caches) e depois supervisord
#             (php-fpm + nginx + schedule:work)
#   queue   -> log das dependências e `queue:work`
#   reverb  -> `reverb:start`
#
# `docker compose run --rm web php artisan migrate` também passa por aqui: o
# comando informado substitui o CMD e é executado no fim, com o mesmo preparo.
# =============================================================================
set -eu

APP_DIR=/var/www/html
cd "$APP_DIR"

log() {
    printf '[entrypoint] %s\n' "$*"
}

# Executa como appuser (uid 1000) quando o container inicia como root, para que
# tudo o que for gravado em storage/ tenha o mesmo dono dos arquivos do host.
as_app() {
    if [ "$(id -u)" = "0" ]; then
        su-exec appuser "$@"
    else
        "$@"
    fi
}

# ---------------------------------------------------------------------------
# 1. Diretórios persistentes (o volume ./storage pode chegar vazio)
# ---------------------------------------------------------------------------
mkdir -p \
    storage/app/public \
    storage/app/private/tarot \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

if [ "$(id -u)" = "0" ]; then
    chown -R appuser:appuser storage bootstrap/cache
fi

# ---------------------------------------------------------------------------
# 2. Sanidade do .env (vem do host; nunca é copiado para dentro da imagem)
# ---------------------------------------------------------------------------
if [ ! -f .env ]; then
    log "ERRO: $APP_DIR/.env não encontrado."
    log "Confira o volume do serviço em docker-compose.prod.yml (./.env:/var/www/html/.env:ro)."
    exit 1
fi

if ! grep -q '^APP_KEY=base64:' .env; then
    log "AVISO: APP_KEY ausente ou fora do formato base64 (php artisan key:generate)."
fi

# ---------------------------------------------------------------------------
# 3. Preparo específico do container web
# ---------------------------------------------------------------------------
role="${CONTAINER_ROLE:-web}"

if [ "$role" = "web" ]; then
    if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
        log "executando migrations (RUN_MIGRATIONS=1)"
        as_app php artisan migrate --force --no-interaction
    fi

    if [ "${SKIP_OPTIMIZE:-0}" != "1" ]; then
        log "aquecendo caches: config, rotas, eventos e views"
        as_app php artisan config:cache
        as_app php artisan route:cache
        as_app php artisan event:cache
        as_app php artisan view:cache
    fi
fi

log "iniciando papel '$role': $*"

exec "$@"

#!/usr/bin/env bash
# =============================================================================
# deploy.sh — publica uma nova versão do Arcana Tarot em UMA instância da VPS
# =============================================================================
#
# O mesmo arquivo serve as duas instalações independentes:
#
#   /var/www/projetos/voz-arcana   -> .env com APP_SLUG=voz-arcana
#   /var/www/projetos/astros21     -> .env com APP_SLUG=astros21
#
# Nada de containers, imagens ou portas está fixo no código: tudo é lido do
# `.env` da instância e do `docker-compose.prod.yml` (via `docker compose
# config`). Um deploy em voz-arcana não toca em astros21 nem na infra
# compartilhada (shared-mysql, shared-redis, shared-proxy), que vivem em
# `deploy/docker-compose.infra.yml` e não são referenciados aqui.
#
# Uso:
#   ./deploy.sh            # publica, pedindo confirmação
#   ./deploy.sh --yes      # publica sem confirmação (cron/pipeline)
#   ./deploy.sh --help     # instruções
#
# Etapas (cada uma aborta o deploy em caso de erro):
#   1. validações do ambiente (git, .env, docker, compose, serviços)
#   2. atualização git (fetch + pull --ff-only; NUNCA descarta trabalho local)
#   3. build da imagem da instância (com cache; só o serviço que tem `build:`)
#   4. migrações + limpeza de caches (uma única vez, num container descartável)
#   5. recriação apenas dos containers desta instância
#   6. healthcheck com tempo limite + logs em caso de falha
#   7. relatório final
#
# Invariantes de segurança desta VPS (2 GB, vários projetos, infra compartilhada):
#   * nunca executa `docker compose down`, `down -v`, `docker system prune`,
#     `docker volume prune`, `docker image prune` ou `docker rm`;
#   * nunca executa `git reset --hard`, `git clean` nem descarta alterações;
#   * nunca edita/sobrescreve o `.env` (é apenas lido, nunca carregado com
#     `source`, para não executar nada do arquivo);
#   * nunca remove imagens: a imagem anterior é preservada com a tag
#     `:...-anterior` para rollback manual.
#
# Documentação: docs/DEPLOY_SCRIPT.md
# =============================================================================

set -Eeuo pipefail

readonly SCRIPT_NAME="${0##*/}"
readonly COMPOSE_FILE_NAME="docker-compose.prod.yml"
readonly BUILD_LOCK_WAIT_SECONDS="${DEPLOY_BUILD_LOCK_WAIT:-1800}"
readonly HEALTH_TIMEOUT_SECONDS="${DEPLOY_HEALTH_TIMEOUT:-240}"
readonly HEALTH_POLL_SECONDS="${DEPLOY_HEALTH_POLL:-5}"
readonly HEALTH_SETTLE_SECONDS="${DEPLOY_HEALTH_SETTLE:-20}"
readonly LOG_TAIL_LINES="${DEPLOY_LOG_TAIL:-80}"

PROJECT_ROOT=""
ENV_FILE=""
COMPOSE_FILE_PATH=""
APP_SLUG=""
APP_NAME=""
APP_IMAGE=""
PROJECT_NAME=""
WEB_SERVICE=""
APP_SERVICES=()
BUILD_SERVICES=()
HEALTH_RESULTS=()
STEP="início"
START_TS=$(date +%s)
ASSUME_YES=0
LOG_FILE=""
LOCK_FILE=""
SERVICES_RESOLVED=0
BRANCH=""
UPSTREAM_REF=""
PREV_COMMIT=""
NEW_COMMIT=""
MIGRATION_RESULT="não executada"
BUILD_RESULT="não executado"
DEPLOY_FINISHED=0

# -----------------------------------------------------------------------------
# Saída colorida apenas em terminal; o arquivo de log é sempre texto puro.
# -----------------------------------------------------------------------------
if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
    C_RESET=$'\033[0m'
    C_INFO=$'\033[36m'
    C_OK=$'\033[32m'
    C_WARN=$'\033[33m'
    C_ERR=$'\033[31m'
    C_STEP=$'\033[1;35m'
else
    C_RESET=""
    C_INFO=""
    C_OK=""
    C_WARN=""
    C_ERR=""
    C_STEP=""
fi

_file_log() {
    if [ -n "$LOG_FILE" ]; then
        printf '%s %s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1" "$2" >>"$LOG_FILE" 2>/dev/null || true
    fi
}

_log() {
    local level=$1 color=$2
    shift 2
    printf '%s %s%s%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$color" "$level" "$C_RESET" "$*"
    _file_log "$level" "$*"
}

info() { _log "INFO " "$C_INFO" "$*"; }
ok() { _log "OK   " "$C_OK" "$*"; }
warn() { _log "AVISO" "$C_WARN" "$*"; }
err() { _log "ERRO " "$C_ERR" "$*" >&2; }

section() {
    STEP="$*"
    printf '\n%s==> %s%s\n' "$C_STEP" "$STEP" "$C_RESET"
    _file_log "PASSO" "$STEP"
}

die() {
    err "$*"
    exit 1
}
# -----------------------------------------------------------------------------
# Utilidades
# -----------------------------------------------------------------------------

# Lê uma chave do `.env` sem executar o arquivo (nada de `source`).
# Valores entre aspas são desembrulhados e CRLF é removido.
env_get() {
    local key=$1 raw value
    raw=$(grep -m1 -E "^[[:space:]]*${key}=" "$ENV_FILE" 2>/dev/null) || raw=""
    if [ -z "$raw" ]; then
        printf ''
        return 0
    fi
    value=${raw#*=}
    value=${value%$'\r'}
    value=${value#\"}
    value=${value%\"}
    value=${value#\'}
    value=${value%\'}
    printf '%s' "$value"
}

# Wrapper único do Compose: sempre o mesmo arquivo e o mesmo projeto.
# O nome do projeto só é fixado depois de resolvido (as primeiras chamadas
# precisam do nome renderizado pelo próprio Compose).
compose() {
    if [ -n "$PROJECT_NAME" ]; then
        docker compose --project-name "$PROJECT_NAME" --file "$COMPOSE_FILE_PATH" "$@"
    else
        docker compose --file "$COMPOSE_FILE_PATH" "$@"
    fi
}

# Executa um comando gravando a saída também no log do deploy.
run_logged() {
    if [ -n "$LOG_FILE" ]; then
        "$@" 2>&1 | tee -a "$LOG_FILE"
    else
        "$@"
    fi
}

lock_path_for_instance() {
    printf '%s/arcana-deploy-%s.lock' \
        "${TMPDIR:-/tmp}" \
        "$(printf '%s' "$PROJECT_ROOT" | cksum | awk '{print $1}')"
}

lock_path_for_build() {
    printf '%s/arcana-tarot-build.lock' "${TMPDIR:-/tmp}"
}

format_duration() {
    local total=$1
    printf '%dm%02ds' $((total / 60)) $((total % 60))
}

usage() {
    cat <<EOF
$SCRIPT_NAME — publica uma nova versão do Arcana Tarot nesta instância

USO
  ./$SCRIPT_NAME [opções]

OPÇÕES
  -y, --yes     Não pede confirmação antes de publicar (para cron/CI).
  -h, --help    Mostra esta ajuda e sai.

O QUE O SCRIPT FAZ
  1. Valida git, .env, Docker, Docker Compose e os serviços do projeto.
  2. git fetch + git pull --ff-only na branch atual.
     Alterações locais em arquivos rastreados interrompem o deploy: nada é
     descartado, não há reset --hard, clean nem merge automático.
  3. Reconstrói a imagem da instância (\$APP_IMAGE) usando cache do Docker.
  4. Roda php artisan optimize:clear + migrate --force uma única vez, num
     container descartável, e recria apenas os serviços da aplicação
     (web, queue, reverb) com a imagem nova.
  5. Aguarda o healthcheck e mostra os logs se algo falhar.

O QUE O SCRIPT NUNCA FAZ
  down / down -v, prune, remoção de volumes ou imagens, restart de MySQL,
  Redis ou Nginx compartilhados, edição do .env, reset/clean do git.

VARIÁVEIS OPCIONAIS
  DEPLOY_HEALTH_TIMEOUT   segundos de espera pelo healthcheck (padrão: 240)
  DEPLOY_HEALTH_POLL      intervalo entre verificações, em segundos (padrão: 5)
  DEPLOY_HEALTH_SETTLE    tempo mínimo no ar para serviço sem healthcheck (20s)
  DEPLOY_BUILD_LOCK_WAIT  segundos de espera pelo lock global de build (1800)
  DEPLOY_LOG_TAIL         linhas de log exibidas em caso de falha (padrão: 80)

ARQUIVOS
  .env                      identidade da instância (APP_SLUG, APP_IMAGE)
  docker-compose.prod.yml   serviços, imagens, healthchecks e volumes
  docs/DEPLOY_SCRIPT.md     documentação completa

SAÍDA
  0  deploy concluído (ou cancelado por você antes de publicar)
  1  falha em alguma etapa (a mensagem indica a etapa e o comando)
  2  uso incorreto
EOF
}

parse_args() {
    while [ $# -gt 0 ]; do
        case "$1" in
            -y | --yes)
                ASSUME_YES=1
                shift
                ;;
            -h | --help)
                usage
                exit 0
                ;;
            --)
                shift
                break
                ;;
            *)
                printf 'Opção desconhecida: %s\n\n' "$1" >&2
                usage >&2
                exit 2
                ;;
        esac
    done
    if [ $# -gt 0 ]; then
        printf 'Argumento inesperado: %s\n\n' "$1" >&2
        usage >&2
        exit 2
    fi
}
# -----------------------------------------------------------------------------
# Preparação: raiz do projeto, log e travas
# -----------------------------------------------------------------------------

resolve_project_root() {
    local script_path="${BASH_SOURCE[0]}" resolved
    resolved=$(readlink -f -- "$script_path" 2>/dev/null) || resolved=$script_path
    PROJECT_ROOT=$(cd -P -- "$(dirname -- "$resolved")" && pwd -P) || die "não foi possível resolver o diretório do script"
    cd "$PROJECT_ROOT" || die "não foi possível entrar em $PROJECT_ROOT"
    ENV_FILE="$PROJECT_ROOT/.env"
    COMPOSE_FILE_PATH="$PROJECT_ROOT/$COMPOSE_FILE_NAME"
}

setup_logging() {
    local dir="$PROJECT_ROOT/storage/logs" stamp
    stamp=$(date '+%Y%m%d-%H%M%S')

    LOG_FILE=""
    if [ -d "$dir" ] && [ -w "$dir" ]; then
        LOG_FILE="$dir/deploy-$stamp.log"
        if ! : >"$LOG_FILE" 2>/dev/null; then
            LOG_FILE=""
        fi
    fi
    if [ -z "$LOG_FILE" ]; then
        LOG_FILE="${TMPDIR:-/tmp}/arcana-deploy-$stamp.log"
        if ! : >"$LOG_FILE" 2>/dev/null; then
            LOG_FILE=""
            warn "não foi possível criar arquivo de log; a saída ficará apenas no terminal"
            return 0
        fi
    fi
    info "log desta execução: $LOG_FILE"
}

acquire_instance_lock() {
    local holder
    command -v flock >/dev/null 2>&1 || die "flock (pacote util-linux) não encontrado; ele impede dois deploys simultâneos"

    LOCK_FILE=$(lock_path_for_instance)
    touch "$LOCK_FILE" 2>/dev/null || die "não foi possível criar o lock em $LOCK_FILE"

    # O fd 9 fica aberto até o processo terminar: o flock é liberado sozinho.
    exec 9>"$LOCK_FILE" || die "não foi possível abrir $LOCK_FILE para o lock"
    if ! flock -n 9; then
        holder=$(cat "$LOCK_FILE" 2>/dev/null || true)
        die "já existe um deploy em execução para este ambiente (lock: $LOCK_FILE${holder:+, pid $holder}). Aguarde a conclusão e tente de novo."
    fi
    printf '%s\n' "$$" >"$LOCK_FILE" 2>/dev/null || true
    info "lock exclusivo desta instância adquirido ($LOCK_FILE)"
}

setup_traps() {
    trap 'on_error "$?" "$LINENO"' ERR
    trap 'on_exit' EXIT
    trap 'on_interrupt' INT TERM
}

on_error() {
    local status=$1 line=$2
    trap - ERR
    set +e
    printf '\n'
    err "deploy interrompido na ${STEP} (linha ${line}, código ${status})"
    err "comando que falhou: ${BASH_COMMAND}"
    dump_service_logs
    [ -z "$LOG_FILE" ] || err "log completo: $LOG_FILE"
    err "diagnóstico: docs/DEPLOY_SCRIPT.md (seção \"Como interpretar erros\")"
    exit "$status"
}

on_exit() {
    local status=$?
    if [ "$DEPLOY_FINISHED" -ne 1 ] && [ "$status" -ne 0 ]; then
        printf '%s Deploy não concluído; duração: %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" \
            "$(format_duration $(($(date +%s) - START_TS)))"
    fi
}

on_interrupt() {
    trap - INT TERM
    err "deploy cancelado pelo operador (Ctrl+C)"
    dump_service_logs
    exit 130
}

# Só faz sentido com os serviços já resolvidos e containers existentes;
# em qualquer outro caso apenas retorna, sem quebrar o trap.
dump_service_logs() {
    set +e
    if [ "${SERVICES_RESOLVED:-0}" -ne 1 ] || [ -z "${PROJECT_NAME:-}" ]; then
        return 0
    fi
    local svc
    for svc in "${APP_SERVICES[@]}"; do
        if [ -z "$(compose ps -q "$svc" 2>/dev/null)" ]; then
            continue
        fi
        printf '\n----- últimos %s logs de %s -----\n' "$LOG_TAIL_LINES" "$svc"
        compose logs --no-color --tail="$LOG_TAIL_LINES" "$svc" 2>&1
    done
    printf '\n'
    return 0
}
# -----------------------------------------------------------------------------
# Etapa 1 — validações
# -----------------------------------------------------------------------------

validate_repository() {
    [ -e "$PROJECT_ROOT/.git" ] || die "não há repositório git em $PROJECT_ROOT (esperado o diretório .git). Rode o script na raiz do clone."
    command -v git >/dev/null 2>&1 || die "git não encontrado no PATH"
    git -C "$PROJECT_ROOT" rev-parse --git-dir >/dev/null 2>&1 || die "o .git de $PROJECT_ROOT não é um repositório git válido"

    local toplevel
    toplevel=$(git -C "$PROJECT_ROOT" rev-parse --show-toplevel 2>/dev/null) || die "não foi possível obter a raiz do repositório git"
    if [ "$toplevel" != "$PROJECT_ROOT" ]; then
        die "o $SCRIPT_NAME deve ficar na raiz do repositório (raiz git detectada: $toplevel)"
    fi
}

validate_env_file() {
    [ -f "$ENV_FILE" ] || die ".env não encontrado em $PROJECT_ROOT. Crie a partir de deploy/env/production.env.example."
    [ -r "$ENV_FILE" ] || die ".env existe mas não pode ser lido pelo usuário $(id -un)"

    local modes group_bit other_bit
    modes=$(stat -c '%a' "$ENV_FILE" 2>/dev/null) || modes=""
    if [ -n "$modes" ]; then
        group_bit=${modes: -2:1}
        other_bit=${modes: -1:1}
        if [ "$group_bit" != "0" ] || [ "$other_bit" != "0" ]; then
            warn ".env com permissões $modes (legível por grupo/outros). Recomendado: chmod 600 .env."
        fi
    fi

    if ! grep -q '^APP_KEY=base64:' "$ENV_FILE"; then
        warn "APP_KEY ausente ou fora do formato base64 no .env; a aplicação pode responder 500."
    fi
}

validate_tooling() {
    command -v docker >/dev/null 2>&1 || die "docker não encontrado no PATH"
    command -v awk >/dev/null 2>&1 || die "awk não encontrado no PATH"
    command -v tee >/dev/null 2>&1 || die "tee não encontrado no PATH"

    if ! docker info >/dev/null 2>&1; then
        die "o daemon do Docker não respondeu. Verifique 'systemctl status docker' e se o usuário $(id -un) está no grupo docker (ou use sudo)."
    fi

    if ! docker compose version >/dev/null 2>&1; then
        die "plugin Docker Compose v2 não encontrado ('docker compose version' falhou). Instale o docker-compose-plugin."
    fi
}

validate_compose_file() {
    [ -f "$COMPOSE_FILE_PATH" ] || die "$COMPOSE_FILE_NAME não encontrado em $PROJECT_ROOT"
}

validate_compose_config() {
    if ! compose config --quiet; then
        die "$COMPOSE_FILE_NAME inválido para esta instância (veja o erro do Compose acima). Nada foi alterado."
    fi
    ok "$COMPOSE_FILE_NAME válido"
}
resolve_instance_identity() {
    local rendered_name images count

    APP_SLUG=$(env_get APP_SLUG)
    APP_IMAGE=$(env_get APP_IMAGE)
    APP_NAME=$(env_get APP_NAME)

    rendered_name=$(compose config 2>/dev/null | sed -n 's/^name:[[:space:]]*//p' | head -n1) || rendered_name=""
    if [ -n "$rendered_name" ]; then
        # O nome resolvido pelo próprio Compose é a fonte da verdade: é o nome
        # que os containers já existentes na VPS usam.
        PROJECT_NAME=$rendered_name
    else
        PROJECT_NAME=${APP_SLUG:-arcana-tarot}
    fi
    if [ -n "$APP_SLUG" ] && [ "$APP_SLUG" != "$PROJECT_NAME" ]; then
        warn "APP_SLUG=$APP_SLUG difere do projeto Compose resolvido ($PROJECT_NAME); usando $PROJECT_NAME"
    fi
    [ -n "$APP_NAME" ] || APP_NAME=$PROJECT_NAME

    if ! images=$(compose config --images 2>/dev/null | sed '/^[[:space:]]*$/d' | sort -u); then
        die "não foi possível ler as imagens declaradas em $COMPOSE_FILE_NAME"
    fi

    if [ -z "$APP_IMAGE" ]; then
        count=$(printf '%s\n' "$images" | wc -l | tr -d ' ')
        if [ "$count" != "1" ]; then
            die "APP_IMAGE não está no .env e o Compose usa $count imagens diferentes. Defina APP_IMAGE (ex.: arcana-tarot/app:${APP_SLUG:-<slug>})."
        fi
        APP_IMAGE=$(printf '%s\n' "$images")
        warn "APP_IMAGE não consta no .env; usando a única imagem do Compose: $APP_IMAGE"
    fi

    if ! printf '%s\n' "$images" | grep -Fxq -- "$APP_IMAGE"; then
        die "APP_IMAGE=$APP_IMAGE não é usada por nenhum serviço de $COMPOSE_FILE_NAME (encontradas: $(printf '%s' "$images" | tr '\n' ' ')). Corrija o .env."
    fi

    ok "instância: ${APP_SLUG:-<sem APP_SLUG>} | projeto Compose: $PROJECT_NAME | imagem: $APP_IMAGE"
}

resolve_services() {
    local -a all_services=()
    local svc svc_cfg svc_image

    mapfile -t all_services < <(compose config --services 2>/dev/null | sed '/^[[:space:]]*$/d' | sort)
    [ "${#all_services[@]}" -gt 0 ] || die "nenhum serviço encontrado em $COMPOSE_FILE_NAME"

    APP_SERVICES=()
    BUILD_SERVICES=()

    for svc in "${all_services[@]}"; do
        svc_cfg=$(compose config "$svc" 2>/dev/null) || die "não foi possível ler a configuração do serviço '$svc'"
        svc_image=$(printf '%s\n' "$svc_cfg" |
            awk '/^[[:space:]]+image:/ { sub(/^[[:space:]]+image:[[:space:]]*/, ""); print; exit }')

        if [ -z "$svc_image" ]; then
            die "não foi possível determinar a imagem do serviço '$svc'; versão do Docker Compose incompatível?"
        fi
        if [ "$svc_image" != "$APP_IMAGE" ]; then
            warn "serviço '$svc' usa a imagem '$svc_image' (não é a desta instância) e será ignorado"
            continue
        fi

        APP_SERVICES+=("$svc")
        if printf '%s\n' "$svc_cfg" | grep -qE '^[[:space:]]+build:'; then
            BUILD_SERVICES+=("$svc")
        fi
    done

    [ "${#APP_SERVICES[@]}" -gt 0 ] || die "nenhum serviço de $COMPOSE_FILE_NAME usa APP_IMAGE=$APP_IMAGE"

    if [ "${#BUILD_SERVICES[@]}" -ne 1 ]; then
        die "esperava exatamente um serviço com bloco 'build:' para construir $APP_IMAGE; encontrei ${#BUILD_SERVICES[@]}. Ajuste o Compose ou o APP_IMAGE."
    fi

    WEB_SERVICE=${BUILD_SERVICES[0]}
    SERVICES_RESOLVED=1
    ok "serviços desta instância: ${APP_SERVICES[*]} (build: $WEB_SERVICE)"
}

check_disk_space() {
    local available_kb
    available_kb=$(df -Pk "$PROJECT_ROOT" 2>/dev/null | awk 'NR==2 {print $4}') || available_kb=""
    case "$available_kb" in
        '' | *[!0-9]*) return 0 ;;
    esac
    if [ "$available_kb" -lt 1048576 ]; then
        warn "apenas $((available_kb / 1024)) MB livres em $PROJECT_ROOT; um build pode falhar por falta de espaço"
    fi
    return 0
}
# -----------------------------------------------------------------------------
# Etapa 2 — atualização do código via git (sem descartar nada)
# -----------------------------------------------------------------------------

validate_git_state() {
    BRANCH=$(git -C "$PROJECT_ROOT" symbolic-ref --short -q HEAD) || BRANCH=""
    [ -n "$BRANCH" ] || die "HEAD destacado (detached) em $PROJECT_ROOT; faça checkout da branch de produção (ex.: git switch main) antes de publicar."

    if git -C "$PROJECT_ROOT" rev-parse -q --verify MERGE_HEAD >/dev/null 2>&1; then
        die "há um merge em andamento neste clone (.git/MERGE_HEAD). Resolva ou aborte antes de publicar."
    fi
    if [ -d "$(git -C "$PROJECT_ROOT" rev-parse --git-path rebase-merge 2>/dev/null)" ] ||
        [ -d "$(git -C "$PROJECT_ROOT" rev-parse --git-path rebase-apply 2>/dev/null)" ]; then
        die "há um rebase em andamento neste clone. Conclua ou aborte antes de publicar."
    fi

    if ! git -C "$PROJECT_ROOT" rev-parse --abbrev-ref --symbolic-full-name '@{u}' >/dev/null 2>&1; then
        die "a branch '$BRANCH' não tem upstream configurado (ex.: git branch --set-upstream-to=origin/$BRANCH $BRANCH)."
    fi
    UPSTREAM_REF=$(git -C "$PROJECT_ROOT" rev-parse --abbrev-ref --symbolic-full-name '@{u}')
}

check_local_changes() {
    local dirty untracked
    dirty=$(git -C "$PROJECT_ROOT" status --porcelain --untracked-files=no)

    if [ -n "$dirty" ]; then
        err "há alterações locais em arquivos rastreados. O deploy não continua e NADA será descartado:"
        printf '%s\n' "$dirty" | sed -n '1,20p'
        die "faça commit/stash dessas alterações (git stash push -m 'antes do deploy') e rode de novo."
    fi

    untracked=$(git -C "$PROJECT_ROOT" ls-files --others --exclude-standard)
    if [ -n "$untracked" ]; then
        warn "existem arquivos não versionados neste clone (não afetam o deploy):"
        printf '%s\n' "$untracked" | sed -n '1,10p'
    fi
}

fetch_remote() {
    PREV_COMMIT=$(git -C "$PROJECT_ROOT" rev-parse HEAD)

    info "git fetch em '$UPSTREAM_REF'"
    if ! run_logged git -C "$PROJECT_ROOT" fetch --quiet; then
        die "git fetch falhou (rede, credenciais ou remoto indisponível). Nada foi alterado."
    fi
    ok "fetch concluído"
}

show_what_changed() {
    local behind ahead incoming
    behind=$(git -C "$PROJECT_ROOT" rev-list --count "HEAD..$UPSTREAM_REF")
    ahead=$(git -C "$PROJECT_ROOT" rev-list --count "$UPSTREAM_REF..HEAD")

    printf '\n'
    info "ambiente ........: ${APP_SLUG:-$PROJECT_NAME} (${APP_NAME})"
    info "diretório .......: $PROJECT_ROOT"
    info "branch ..........: $BRANCH (upstream: $UPSTREAM_REF)"
    info "commit atual ....: $(git -C "$PROJECT_ROOT" log -1 --pretty='%h %s')"
    info "commit destino ..: $(git -C "$PROJECT_ROOT" log -1 --pretty='%h %s' "$UPSTREAM_REF")"
    info "imagem Docker ...: $APP_IMAGE"
    info "serviços ........: ${APP_SERVICES[*]}"

    if [ "$ahead" -gt 0 ]; then
        warn "a branch local tem $ahead commit(s) que não estão em $UPSTREAM_REF (serão publicados junto)."
    fi

    if [ "$behind" -eq 0 ]; then
        ok "nenhum commit novo no remoto; o deploy vai reconstruir/recriar a partir do código atual"
        return 0
    fi

    info "$behind commit(s) novo(s) chegando:"
    incoming=$(git -C "$PROJECT_ROOT" log --oneline --no-decorate "HEAD..$UPSTREAM_REF")
    printf '%s\n' "$incoming" | sed -n '1,20p'
    if [ "$behind" -gt 20 ]; then
        info "... e mais $((behind - 20)) commit(s)"
    fi
}

confirm_deploy() {
    local answer
    if [ "$ASSUME_YES" -eq 1 ]; then
        info "--yes informado: seguindo sem confirmação"
        return 0
    fi

    printf '\n'
    printf 'Publicar esta versão em %s? [s/N] ' "${APP_SLUG:-$PROJECT_NAME}"
    if ! read -r answer; then
        die "entrada não disponível (sem terminal). Use --yes para publicar sem confirmação."
    fi
    case "${answer,,}" in
        s | sim | y | yes) return 0 ;;
        *)
            info "publicação cancelada pelo operador; nada foi alterado"
            DEPLOY_FINISHED=1
            exit 0
            ;;
    esac
}

pull_code() {
    local remote=$1 ref=${UPSTREAM_REF#*/}
    info "git pull --ff-only ($BRANCH <- $UPSTREAM_REF)"
    if ! run_logged git -C "$PROJECT_ROOT" pull --ff-only --no-rebase --quiet "$remote" "$ref"; then
        die "git pull --ff-only falhou: o histórico local divergiu do remoto. Nada foi descartado; resolva manualmente (git status) e rode de novo."
    fi
    NEW_COMMIT=$(git -C "$PROJECT_ROOT" rev-parse HEAD)
    if [ "$NEW_COMMIT" = "$PREV_COMMIT" ]; then
        info "código já estava atualizado (commit $(git -C "$PROJECT_ROOT" rev-parse --short HEAD))"
    else
        ok "código atualizado: $(git -C "$PROJECT_ROOT" rev-parse --short "$PREV_COMMIT") -> $(git -C "$PROJECT_ROOT" rev-parse --short "$NEW_COMMIT")"
    fi
}
# -----------------------------------------------------------------------------
# Etapa 3 — build da imagem (apenas o serviço que tem `build:`, mantendo cache)
# -----------------------------------------------------------------------------

acquire_build_lock() {
    local path
    path=$(lock_path_for_build)
    if ! touch "$path" 2>/dev/null; then
        warn "não foi possível criar $path; seguindo sem o lock global de build"
        return 0
    fi
    if ! exec 8>"$path"; then
        warn "não foi possível abrir $path; seguindo sem o lock global de build"
        return 0
    fi
    if ! flock -w "$BUILD_LOCK_WAIT_SECONDS" 8; then
        die "outro deploy do Arcana Tarot está construindo uma imagem há mais de ${BUILD_LOCK_WAIT_SECONDS}s (lock: $path). Tente de novo em alguns minutos."
    fi
    info "lock global de build adquirido: nenhum outro build do Arcana roda junto"
}

release_build_lock() {
    flock -u 8 2>/dev/null || true
    exec 8>&- 2>/dev/null || true
    return 0
}

# Guarda a imagem atual com outra tag para rollback manual. Nunca remove nada.
tag_previous_image() {
    local previous_tag="${APP_IMAGE}-anterior"
    if ! docker image inspect "$APP_IMAGE" >/dev/null 2>&1; then
        info "não existe imagem $APP_IMAGE no host ainda (primeiro deploy desta instância)"
        return 0
    fi
    if docker tag "$APP_IMAGE" "$previous_tag" 2>/dev/null; then
        info "imagem atual preservada como $previous_tag (rollback manual)"
    else
        warn "não foi possível marcar a imagem atual como $previous_tag"
    fi
}

build_image() {
    local started
    started=$(date +%s)
    info "docker compose build $WEB_SERVICE para a tag $APP_IMAGE (com cache do Docker)"
    if ! run_logged compose build "$WEB_SERVICE"; then
        BUILD_RESULT="falhou"
        die "o build da imagem falhou. Os containers em execução NÃO foram tocados e continuam na versão anterior."
    fi
    BUILD_RESULT="sucesso ($(format_duration $(($(date +%s) - started))))"
    ok "imagem construída: $APP_IMAGE"
}

# -----------------------------------------------------------------------------
# Etapa 4 — migrações e caches (uma única vez, fora dos containers de produção)
# -----------------------------------------------------------------------------

run_migrations() {
    local started
    started=$(date +%s)
    info "container descartável de '$WEB_SERVICE': optimize:clear + migrate --force + optimize"
    info "os containers de produção seguem no ar durante esta etapa"

    # SKIP_OPTIMIZE/RUN_MIGRATIONS=0: o entrypoint não deve repetir migrations
    # nem reaquecer cache antes do comando abaixo (ele continua sendo executado,
    # garantindo o mesmo preparo de diretórios/permissões).
    if ! run_logged compose run --rm --no-deps \
        -e SKIP_OPTIMIZE=1 \
        -e RUN_MIGRATIONS=0 \
        "$WEB_SERVICE" sh -c 'php artisan optimize:clear && php artisan migrate --force --no-interaction && php artisan optimize --no-interaction'; then
        MIGRATION_RESULT="falhou"
        die "migrações/caches falharam. Nenhum container foi recriado: a versão anterior continua no ar."
    fi

    MIGRATION_RESULT="sucesso ($(format_duration $(($(date +%s) - started))))"
    ok "migrações aplicadas e caches validados com a imagem nova"
}
# -----------------------------------------------------------------------------
# Etapa 5 — recriação apenas dos containers desta instância
# -----------------------------------------------------------------------------

recreate_containers() {
    info "compose up -d --no-deps --no-build ${APP_SERVICES[*]}"
    if ! run_logged compose up -d --no-deps --no-build "${APP_SERVICES[@]}"; then
        die "falha ao recriar os containers (${APP_SERVICES[*]}). Inspecione com: docker compose -f $COMPOSE_FILE_NAME ps -a"
    fi
    verify_running_image
}

# Garante que cada container está rodando com a imagem recém-construída; se o
# Compose resolviu a imagem por ID/cache, força a recriação daquele serviço.
verify_running_image() {
    local expected actual cid svc
    expected=$(docker image inspect --format '{{.Id}}' "$APP_IMAGE" 2>/dev/null) || die "não foi possível inspecionar a imagem $APP_IMAGE"

    for svc in "${APP_SERVICES[@]}"; do
        cid=$(compose ps -q "$svc" 2>/dev/null) || cid=""
        if [ -z "$cid" ]; then
            die "o container do serviço '$svc' não existe após o up (docker compose -f $COMPOSE_FILE_NAME ps -a para detalhes)"
        fi
        actual=$(docker inspect --format '{{.Image}}' "$cid" 2>/dev/null) || actual=""
        if [ "$actual" != "$expected" ]; then
            warn "o container '$svc' ainda usa a imagem anterior; recriando apenas ele"
            compose up -d --no-deps --no-build --force-recreate "$svc" ||
                die "não foi possível recriar o container '$svc'"
            cid=$(compose ps -q "$svc" 2>/dev/null) || cid=""
            actual=$(docker inspect --format '{{.Image}}' "$cid" 2>/dev/null) || actual=""
            if [ "$actual" != "$expected" ]; then
                die "o container '$svc' continua na imagem antiga mesmo após --force-recreate"
            fi
        fi
    done
    ok "todos os containers usam a imagem recém-construída"
}

# -----------------------------------------------------------------------------
# Etapa 6 — healthcheck com tempo limite
# -----------------------------------------------------------------------------

# Estados possíveis: missing | stopped | starting | healthy | unhealthy | none
service_state() {
    local svc=$1 cid running health
    cid=$(compose ps -q "$svc" 2>/dev/null) || cid=""
    if [ -z "$cid" ]; then
        printf 'missing'
        return 0
    fi
    running=$(docker inspect --format '{{.State.Running}}' "$cid" 2>/dev/null) || running=false
    if [ "$running" != "true" ]; then
        printf 'stopped'
        return 0
    fi
    health=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$cid" 2>/dev/null) || health=none
    [ -n "$health" ] || health=none
    printf '%s' "$health"
}

wait_for_service() {
    local svc=$1 state now started deadline settle_after last_note cid restarts
    started=$(date +%s)
    deadline=$((started + HEALTH_TIMEOUT_SECONDS))
    settle_after=$((started + HEALTH_SETTLE_SECONDS))
    last_note=$started

    info "aguardando '$svc' (limite ${HEALTH_TIMEOUT_SECONDS}s)"
    while :; do
        state=$(service_state "$svc")
        now=$(date +%s)

        case "$state" in
            healthy)
                HEALTH_RESULTS+=("$svc=healthy")
                ok "'$svc' está healthy"
                return 0
                ;;
            none)
                # Serviço sem healthcheck: exige execução estável, sem reinícios.
                cid=$(compose ps -q "$svc" 2>/dev/null) || cid=""
                restarts=$(docker inspect --format '{{.RestartCount}}' "$cid" 2>/dev/null) || restarts=0
                if [ "$now" -ge "$settle_after" ] && [ "${restarts:-0}" -eq 0 ]; then
                    HEALTH_RESULTS+=("$svc=em execução há $((now - started))s, sem healthcheck")
                    ok "'$svc' em execução estável (serviço não declara healthcheck)"
                    return 0
                fi
                ;;
        esac

        if [ "$now" -ge "$deadline" ]; then
            HEALTH_RESULTS+=("$svc=$state")
            err "'$svc' não ficou saudável em ${HEALTH_TIMEOUT_SECONDS}s (último estado: $state)"
            return 1
        fi

        if [ $((now - last_note)) -ge 15 ]; then
            info "'$svc': estado atual $state ($((now - started))s)"
            last_note=$now
        fi
        sleep "$HEALTH_POLL_SECONDS"
    done
}

wait_for_health() {
    local svc
    for svc in "${APP_SERVICES[@]}"; do
        if ! wait_for_service "$svc"; then
            dump_service_logs
            die "healthcheck de '$svc' falhou. A versão anterior continua disponível como ${APP_IMAGE}-anterior (veja docs/DEPLOY_SCRIPT.md)."
        fi
    done
    ok "todos os serviços passaram na verificação"
}
# -----------------------------------------------------------------------------
# Etapa 7 — relatório final
# -----------------------------------------------------------------------------

print_report() {
    set +e
    local total old_commit new_commit image_id health_line svc
    total=$(($(date +%s) - START_TS))
    image_id=$(docker image inspect --format '{{.Id}}' "$APP_IMAGE" 2>/dev/null | cut -c1-19)

    health_line=""
    for svc in "${HEALTH_RESULTS[@]}"; do
        if [ -z "$health_line" ]; then
            health_line=$svc
        else
            health_line="$health_line | $svc"
        fi
    done
    [ -n "$health_line" ] || health_line="não verificado"

    old_commit=$(git -C "$PROJECT_ROOT" log -1 --pretty='%h %s' "$PREV_COMMIT" 2>/dev/null)
    new_commit=$(git -C "$PROJECT_ROOT" log -1 --pretty='%h %s' "${NEW_COMMIT:-$PREV_COMMIT}" 2>/dev/null)

    printf '\n'
    printf '================ RELATÓRIO DO DEPLOY ================\n'
    printf 'Ambiente .........: %s (%s)\n' "${APP_SLUG:-$PROJECT_NAME}" "$APP_NAME"
    printf 'Diretório ........: %s\n' "$PROJECT_ROOT"
    printf 'Branch ...........: %s (upstream %s)\n' "$BRANCH" "$UPSTREAM_REF"
    printf 'Commit anterior ..: %s\n' "${old_commit:-desconhecido}"
    printf 'Commit publicado .: %s\n' "${new_commit:-desconhecido}"
    printf 'Imagem Docker ....: %s (%s)\n' "$APP_IMAGE" "${image_id:-id desconhecido}"
    printf 'Build ............: %s\n' "$BUILD_RESULT"
    printf 'Serviços .........: %s\n' "${APP_SERVICES[*]}"
    printf 'Migrations .......: %s\n' "$MIGRATION_RESULT"
    printf 'Healthchecks .....: %s\n' "$health_line"
    printf 'Duração total ....: %s\n' "$(format_duration "$total")"
    printf 'Log desta execução: %s\n' "${LOG_FILE:-<sem arquivo de log>}"
    printf 'Rollback manual ..: docker tag %s-anterior %s && docker compose -f %s --project-name %s up -d --no-deps --force-recreate %s\n' \
        "$APP_IMAGE" "$APP_IMAGE" "$COMPOSE_FILE_NAME" "$PROJECT_NAME" "${APP_SERVICES[*]}"
    printf '\nContainers desta instância (projeto Compose %s):\n' "$PROJECT_NAME"
    if [ -n "$(compose ps -q 2>/dev/null)" ]; then
        compose ps 2>/dev/null | sed 's/^/  /'
    else
        printf '  (nenhum container encontrado para este projeto)\n'
    fi
    printf '\nOK: deploy concluído.\n'
    printf '=====================================================\n'
    _file_log "RELATÓRIO" "ambiente=${APP_SLUG:-$PROJECT_NAME} commit=${NEW_COMMIT:-$PREV_COMMIT} imagem=$APP_IMAGE migrations=$MIGRATION_RESULT health=$health_line duração=$(format_duration "$total")"
    return 0
}

# -----------------------------------------------------------------------------
# Fluxo principal
# -----------------------------------------------------------------------------

main() {
    parse_args "$@"
    resolve_project_root
    setup_logging
    setup_traps

    printf '%s\n' "=== $SCRIPT_NAME — $(date '+%d/%m/%Y %H:%M:%S') ==="
    _file_log "INÍCIO" "script=$SCRIPT_NAME raiz=$PROJECT_ROOT usuário=$(id -un)"

    acquire_instance_lock

    section "Etapa 1/7 — validações do ambiente"
    validate_repository
    validate_env_file
    validate_tooling
    validate_compose_file
    validate_compose_config
    resolve_instance_identity
    resolve_services
    check_disk_space
    ok "ambiente validado; nada foi alterado até aqui"

    section "Etapa 2/7 — atualização do código (git)"
    validate_git_state
    check_local_changes
    fetch_remote
    show_what_changed
    confirm_deploy
    pull_code "${UPSTREAM_REF%%/*}"

    section "Etapa 3/7 — build da imagem"
    acquire_build_lock
    tag_previous_image
    build_image
    release_build_lock

    section "Etapa 4/7 — migrações e caches"
    run_migrations

    section "Etapa 5/7 — recriação dos containers"
    recreate_containers

    section "Etapa 6/7 — healthcheck"
    wait_for_health

    section "Etapa 7/7 — relatório"
    DEPLOY_FINISHED=1
    print_report
}

# Permite carregar as funções em testes (`source deploy.sh`) sem executar nada.
if [ "${BASH_SOURCE[0]}" = "$0" ]; then
    main "$@"
fi

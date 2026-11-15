#!/bin/sh
# =============================================================================
# Inicialização do MariaDB compartilhado — cria um banco e um usuário por
# instância (astros21 e voz-arcana) com permissões apenas no próprio schema.
# =============================================================================
# Executado pelo entrypoint oficial da imagem `mariadb:10.11` SOMENTE quando o
# volume de dados está vazio (primeiro boot). Em um servidor já inicializado
# este arquivo é ignorado: rode os comandos manualmente (ver DEPLOY.md).
#
# Senhas: sem aspas simples, aspas duplas, `$` ou `\` (o script recusa).
# =============================================================================
set -eu

ROOT_PASSWORD="${MARIADB_ROOT_PASSWORD:-${MYSQL_ROOT_PASSWORD:-}}"

if [ -z "$ROOT_PASSWORD" ]; then
    echo "[init] ERRO: MARIADB_ROOT_PASSWORD não definida." >&2
    exit 1
fi

# Cliente oficial da imagem; usa o socket do servidor temporário de init.
mysql_root() {
    mysql --user=root --password="$ROOT_PASSWORD" --protocol=socket --batch --skip-column-names "$@"
}

validate_password() {
    case "$1" in
        *\'* | *\"* | *\$* | *\\*)
            echo "[init] ERRO: a senha de '$2' contém caractere não suportado (', \", \$ ou \\)." >&2
            exit 1
            ;;
        '')
            echo "[init] ERRO: senha vazia para '$2'." >&2
            exit 1
            ;;
    esac
}

create_database_and_user() {
    db_name="$1"
    db_user="$2"
    db_password="$3"

    validate_password "$db_password" "$db_user"

    echo "[init] criando banco '$db_name' e usuário '$db_user'..."

    mysql_root <<SQL
CREATE DATABASE IF NOT EXISTS \`${db_name}\`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${db_user}'@'%' IDENTIFIED BY '${db_password}';
ALTER USER '${db_user}'@'%' IDENTIFIED BY '${db_password}';
GRANT ALL PRIVILEGES ON \`${db_name}\`.* TO '${db_user}'@'%';
FLUSH PRIVILEGES;
SQL
}

create_database_and_user \
    "${ASTROS21_DB_NAME:-astros21_db}" \
    "${ASTROS21_DB_USER:-astros21}" \
    "${ASTROS21_DB_PASSWORD:-}"

create_database_and_user \
    "${VOZ_ARCANA_DB_NAME:-voz_arcana_db}" \
    "${VOZ_ARCANA_DB_USER:-voz_arcana}" \
    "${VOZ_ARCANA_DB_PASSWORD:-}"

echo "[init] bancos disponíveis:"
mysql_root -e "SELECT schema_name FROM information_schema.schemata WHERE schema_name IN ('${ASTROS21_DB_NAME:-astros21_db}', '${VOZ_ARCANA_DB_NAME:-voz_arcana_db}');"

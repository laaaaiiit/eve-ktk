#!/bin/bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="${SCRIPT_DIR%/bin}"
ENV_FILE="$ROOT_DIR/.env"
DOMAINIZE_SQL="$ROOT_DIR/db/v3/010_domainize_existing_schema.sql"

if [[ -f "$ENV_FILE" ]]; then
	set -a
	# shellcheck disable=SC1090
	. "$ENV_FILE"
	set +a
fi

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"
DB_USER="${DB_USER:-eve-ng-ktk}"
DB_PASSWORD="${DB_PASSWORD:-}"
SOURCE_DB_NAME="${SOURCE_DB_NAME:-eve-ng-db}"
TARGET_DB_NAME="${TARGET_DB_NAME:-eve-ng-db-v3}"

if [[ -z "$DB_PASSWORD" ]]; then
	echo "ERROR: DB_PASSWORD is required (.env or env var)" >&2
	exit 1
fi
if [[ ! -f "$DOMAINIZE_SQL" ]]; then
	echo "ERROR: Domainize SQL not found: $DOMAINIZE_SQL" >&2
	exit 1
fi

echo "[v3] Recreate target database: $TARGET_DB_NAME"
sudo -u postgres psql -d postgres -v ON_ERROR_STOP=1 \
	-c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$TARGET_DB_NAME' AND pid <> pg_backend_pid();" >/dev/null || true
sudo -u postgres dropdb --if-exists "$TARGET_DB_NAME"
sudo -u postgres createdb -O "$DB_USER" "$TARGET_DB_NAME"

echo "[v3] Clone schema+data from source: $SOURCE_DB_NAME -> $TARGET_DB_NAME"
PGPASSWORD="$DB_PASSWORD" pg_dump --no-owner --no-privileges \
	"host=$DB_HOST port=$DB_PORT dbname=$SOURCE_DB_NAME user=$DB_USER" \
| PGPASSWORD="$DB_PASSWORD" psql \
	"host=$DB_HOST port=$DB_PORT dbname=$TARGET_DB_NAME user=$DB_USER" \
	-v ON_ERROR_STOP=1 >/dev/null

echo "[v3] Domainize tables and drop cross-domain FKs"
PGPASSWORD="$DB_PASSWORD" psql \
	"host=$DB_HOST port=$DB_PORT dbname=$TARGET_DB_NAME user=$DB_USER" \
	-v ON_ERROR_STOP=1 \
	-f "$DOMAINIZE_SQL" >/dev/null

echo "[v3] Set default search_path"
PGPASSWORD="$DB_PASSWORD" psql \
	"host=$DB_HOST port=$DB_PORT dbname=postgres user=$DB_USER" \
	-v ON_ERROR_STOP=1 \
	-c "ALTER DATABASE \"$TARGET_DB_NAME\" SET search_path = auth, infra, labs, runtime, checks, public;" >/dev/null

echo "[v3] Done. Summary:"
PGPASSWORD="$DB_PASSWORD" psql \
	"host=$DB_HOST port=$DB_PORT dbname=$TARGET_DB_NAME user=$DB_USER" \
	-v ON_ERROR_STOP=1 \
	-c "SELECT n.nspname AS schema_name, COUNT(*) AS table_count FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relkind='r' AND n.nspname IN ('auth','infra','labs','runtime','checks') GROUP BY n.nspname ORDER BY n.nspname;"

PGPASSWORD="$DB_PASSWORD" psql \
	"host=$DB_HOST port=$DB_PORT dbname=$TARGET_DB_NAME user=$DB_USER" \
	-v ON_ERROR_STOP=1 \
	-c "SELECT COUNT(*) AS cross_schema_fk FROM pg_constraint con JOIN pg_class c_src ON c_src.oid = con.conrelid JOIN pg_namespace n_src ON n_src.oid = c_src.relnamespace JOIN pg_class c_dst ON c_dst.oid = con.confrelid JOIN pg_namespace n_dst ON n_dst.oid = c_dst.relnamespace WHERE con.contype='f' AND n_src.nspname <> n_dst.nspname;"

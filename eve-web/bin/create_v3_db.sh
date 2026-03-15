#!/bin/bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="${SCRIPT_DIR%/bin}"

if [[ -f "$ROOT_DIR/.env" ]]; then
	set -a
	# shellcheck disable=SC1091
	source "$ROOT_DIR/.env"
	set +a
fi

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"
DB_USER="${DB_USER:-eve-ng-ktk}"
DB_PASSWORD="${DB_PASSWORD:-}"
V3_DB_NAME="${V3_DB_NAME:-eve-ng-db-v3}"
SCHEMA_FILE="${SCHEMA_FILE:-$ROOT_DIR/db/v3/001_schema.sql}"

if [[ -z "$DB_PASSWORD" ]]; then
	echo "ERROR: DB_PASSWORD is required (.env or environment)"
	exit 1
fi

if [[ ! -f "$SCHEMA_FILE" ]]; then
	echo "ERROR: schema file not found: $SCHEMA_FILE"
	exit 1
fi

echo "Recreating database $V3_DB_NAME (owner: $DB_USER)"
sudo -u postgres dropdb --if-exists "$V3_DB_NAME"
sudo -u postgres createdb -O "$DB_USER" "$V3_DB_NAME"

echo "Applying schema: $SCHEMA_FILE"
PGPASSWORD="$DB_PASSWORD" psql \
	"host=$DB_HOST port=$DB_PORT dbname=$V3_DB_NAME user=$DB_USER" \
	-v ON_ERROR_STOP=1 \
	-f "$SCHEMA_FILE"

echo "Database ready: $V3_DB_NAME"
PGPASSWORD="$DB_PASSWORD" psql \
	"host=$DB_HOST port=$DB_PORT dbname=$V3_DB_NAME user=$DB_USER" \
	-v ON_ERROR_STOP=1 \
	-c "SELECT nspname AS schema_name FROM pg_namespace WHERE nspname IN ('auth','infra','labs','runtime','checks','ops') ORDER BY nspname;"

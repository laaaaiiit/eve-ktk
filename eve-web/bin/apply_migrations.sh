#!/bin/bash
set -euo pipefail

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"
DB_NAME="${DB_NAME:-eve-ng-db}"
DB_USER="${DB_USER:-eve-ng-ktk}"
DB_PASSWORD="${DB_PASSWORD:-}"
MIGRATIONS_TABLE="${MIGRATIONS_TABLE:-schema_migrations}"

if [[ -z "$DB_PASSWORD" ]]; then
  echo "ERROR: set DB_PASSWORD env var"
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
MIGRATIONS_DIR="${SCRIPT_DIR%/bin}/migrations"
CONN_STR="host=$DB_HOST port=$DB_PORT dbname=$DB_NAME user=$DB_USER"

if ! compgen -G "$MIGRATIONS_DIR/*.sql" >/dev/null; then
  echo "No migrations found in $MIGRATIONS_DIR"
  exit 0
fi

PGPASSWORD="$DB_PASSWORD" psql "$CONN_STR" -v ON_ERROR_STOP=1 <<SQL
CREATE TABLE IF NOT EXISTS public.${MIGRATIONS_TABLE} (
  version text PRIMARY KEY,
  applied_at timestamptz NOT NULL DEFAULT NOW()
);
SQL

for sql in "$MIGRATIONS_DIR"/*.sql; do
  version="$(basename "$sql")"
  already_applied="$(
    PGPASSWORD="$DB_PASSWORD" psql "$CONN_STR" -At -v ON_ERROR_STOP=1 \
      -c "SELECT 1 FROM public.${MIGRATIONS_TABLE} WHERE version = '$version' LIMIT 1;"
  )"
  if [[ "$already_applied" == "1" ]]; then
    echo "Skipping $version (already applied)"
    continue
  fi

  echo "Applying $version"
  PGPASSWORD="$DB_PASSWORD" psql "$CONN_STR" -v ON_ERROR_STOP=1 <<SQL
BEGIN;
\i $sql
INSERT INTO public.${MIGRATIONS_TABLE} (version) VALUES ('$version');
COMMIT;
SQL
done

echo "Migrations applied successfully"

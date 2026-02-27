#!/bin/bash
set -euo pipefail

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"
DB_NAME="${DB_NAME:-eve-ng-db}"
DB_USER="${DB_USER:-eve-ng-ktk}"
DB_PASSWORD="${DB_PASSWORD:-}"

if [[ -z "$DB_PASSWORD" ]]; then
  echo "ERROR: set DB_PASSWORD env var"
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
MIGRATIONS_DIR="${SCRIPT_DIR%/bin}/migrations"

for sql in "$MIGRATIONS_DIR"/*.sql; do
  echo "Applying $(basename "$sql")"
  PGPASSWORD="$DB_PASSWORD" psql \
    "host=$DB_HOST port=$DB_PORT dbname=$DB_NAME user=$DB_USER" \
    -v ON_ERROR_STOP=1 \
    -f "$sql"
done

echo "Migrations applied successfully"

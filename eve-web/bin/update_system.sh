#!/bin/bash
set -euo pipefail

TARGET_DIR="${TARGET_DIR:-/opt/unetlab}"
REPO_URL="${REPO_URL:-https://github.com/laaaaiiit/eve-ktk.git}"
BRANCH="${BRANCH:-main}"
TMP_BASE="${TMP_BASE:-/opt/unetlab/data/tmp}"
BACKUP_DIR="${BACKUP_DIR:-/opt/unetlab/data/backups/update}"
ENV_FILE_REL="${ENV_FILE_REL:-eve-web/.env}"
MIGRATE_SCRIPT_REL="${MIGRATE_SCRIPT_REL:-eve-web/bin/apply_migrations.sh}"
CURL_CONNECT_TIMEOUT="${CURL_CONNECT_TIMEOUT:-15}"
CURL_MAX_TIME="${CURL_MAX_TIME:-600}"

DRY_RUN=0
SKIP_BACKUP=0
SKIP_MIGRATIONS=0
RESTART_SERVICES=1

timestamp() {
	date +"%Y-%m-%d %H:%M:%S"
}

log() {
	echo "[$(timestamp)] $*"
}

warn() {
	echo "[$(timestamp)] WARN: $*" >&2
}

fail() {
	echo "[$(timestamp)] ERROR: $*" >&2
	exit 1
}

usage() {
	cat <<'USAGE'
Usage:
  update_system.sh [options]

Options:
  --repo <url>             GitHub repository URL (default: https://github.com/laaaaiiit/eve-ktk.git)
  --branch <name>          Branch name to download (default: main)
  --target-dir <path>      Target path to update (default: /opt/unetlab)
  --tmp-base <path>        Temp base path (default: /opt/unetlab/data/tmp)
  --backup-dir <path>      Backup path (default: /opt/unetlab/data/backups/update)
  --skip-backup            Skip code backup before update
  --skip-migrations        Skip DB migrations
  --no-restart             Do not restart/reload services after update
  --dry-run                Print what would be done without applying changes
  -h, --help               Show this help

Environment:
  GITHUB_TOKEN             Optional token for private GitHub repo download
  CURL_CONNECT_TIMEOUT     Curl connect timeout in seconds (default: 15)
  CURL_MAX_TIME            Curl total max time in seconds (default: 600)
USAGE
}

require_cmd() {
	local cmd="$1"
	command -v "$cmd" >/dev/null 2>&1 || fail "Command not found: $cmd"
}

restart_service_if_present() {
	local service="$1"
	local action="$2"

	if ! systemctl list-unit-files "$service" >/dev/null 2>&1; then
		log "Service not found, skip: $service"
		return 0
	fi

	log "systemctl $action $service"
	if ! systemctl "$action" "$service"; then
		warn "Failed: systemctl $action $service"
	fi
}

while [[ $# -gt 0 ]]; do
	case "$1" in
		--repo)
			[[ $# -ge 2 ]] || fail "--repo requires value"
			REPO_URL="$2"
			shift 2
			;;
		--branch)
			[[ $# -ge 2 ]] || fail "--branch requires value"
			BRANCH="$2"
			shift 2
			;;
		--target-dir)
			[[ $# -ge 2 ]] || fail "--target-dir requires value"
			TARGET_DIR="$2"
			shift 2
			;;
		--tmp-base)
			[[ $# -ge 2 ]] || fail "--tmp-base requires value"
			TMP_BASE="$2"
			shift 2
			;;
		--backup-dir)
			[[ $# -ge 2 ]] || fail "--backup-dir requires value"
			BACKUP_DIR="$2"
			shift 2
			;;
		--skip-backup)
			SKIP_BACKUP=1
			shift
			;;
		--skip-migrations)
			SKIP_MIGRATIONS=1
			shift
			;;
		--no-restart)
			RESTART_SERVICES=0
			shift
			;;
		--dry-run)
			DRY_RUN=1
			shift
			;;
		-h|--help)
			usage
			exit 0
			;;
		*)
			fail "Unknown option: $1"
			;;
	esac
done

[[ "$EUID" -eq 0 ]] || fail "Run as root"
[[ -d "$TARGET_DIR" ]] || fail "Target directory not found: $TARGET_DIR"
[[ -d "$TARGET_DIR/eve-web" ]] || fail "Expected directory not found: $TARGET_DIR/eve-web"

require_cmd curl
require_cmd tar
require_cmd rsync
require_cmd bash

if [[ "$SKIP_MIGRATIONS" -ne 1 ]]; then
	require_cmd psql
fi

mkdir -p "$TMP_BASE"
tmp_dir="$(mktemp -d "$TMP_BASE/update-system.XXXXXX")"
trap 'rm -rf "$tmp_dir"' EXIT

repo_base="${REPO_URL%.git}"
repo_base="${repo_base%/}"
archive_url="${repo_base}/archive/refs/heads/${BRANCH}.tar.gz"
archive_file="$tmp_dir/source.tar.gz"
extract_dir="$tmp_dir/extract"

log "Downloading archive: $archive_url"
curl_args=(
	-fL
	--connect-timeout "$CURL_CONNECT_TIMEOUT"
	--max-time "$CURL_MAX_TIME"
	--retry 3
	--retry-delay 2
	--retry-connrefused
)
if [[ -n "${GITHUB_TOKEN:-}" ]]; then
	curl_args+=(-H "Authorization: Bearer ${GITHUB_TOKEN}")
fi
if ! curl "${curl_args[@]}" "$archive_url" -o "$archive_file"; then
	fail "Failed to download archive: $archive_url"
fi

mkdir -p "$extract_dir"
tar -xzf "$archive_file" -C "$extract_dir"

source_dir="$(find "$extract_dir" -mindepth 1 -maxdepth 1 -type d | head -n 1)"
[[ -n "${source_dir:-}" ]] || fail "Failed to detect extracted source directory"
[[ -f "$source_dir/eve-web/bin/apply_migrations.sh" ]] || fail "Downloaded archive is not expected project layout"

backup_file=""
if [[ "$SKIP_BACKUP" -eq 1 ]]; then
	log "Backup skipped by option"
else
	mkdir -p "$BACKUP_DIR"
	backup_file="$BACKUP_DIR/unetlab-code-$(date +%Y%m%d-%H%M%S).tar.gz"
	if [[ "$DRY_RUN" -eq 1 ]]; then
		log "DRY-RUN: would create backup: $backup_file"
	else
		log "Creating backup: $backup_file"
		tar -czf "$backup_file" \
			--exclude='.git' \
			--exclude='data' \
			--exclude='addons' \
			--exclude='node_modules' \
			-C "$TARGET_DIR" .
	fi
fi

rsync_args=(
	-a
	--delete
	--human-readable
	--stats
	--exclude='.git'
	--exclude='data'
	--exclude='addons'
	--exclude='node_modules'
	--exclude='eve-web/.env'
)
if [[ "$DRY_RUN" -eq 1 ]]; then
	rsync_args+=(--dry-run)
fi

log "Syncing files to $TARGET_DIR"
rsync "${rsync_args[@]}" "$source_dir/" "$TARGET_DIR/"

if [[ "$DRY_RUN" -eq 1 ]]; then
	log "DRY-RUN completed. No changes were applied."
	exit 0
fi

if [[ "$SKIP_MIGRATIONS" -eq 1 ]]; then
	log "Migrations skipped by option"
else
	env_file="$TARGET_DIR/$ENV_FILE_REL"
	[[ -f "$env_file" ]] || fail "Env file not found: $env_file"

	set -a
	# shellcheck disable=SC1090
	source "$env_file"
	set +a

	: "${DB_HOST:=127.0.0.1}"
	: "${DB_PORT:=5432}"
	: "${DB_NAME:=eve-ng-db}"
	: "${DB_USER:=eve-ng-ktk}"
	: "${DB_PASSWORD:=}"
	[[ -n "$DB_PASSWORD" ]] || fail "DB_PASSWORD is empty in $env_file"

	migrate_script="$TARGET_DIR/$MIGRATE_SCRIPT_REL"
	[[ -x "$migrate_script" ]] || fail "Migration script is not executable: $migrate_script"

	log "Applying migrations"
	DB_HOST="$DB_HOST" \
	DB_PORT="$DB_PORT" \
	DB_NAME="$DB_NAME" \
	DB_USER="$DB_USER" \
	DB_PASSWORD="$DB_PASSWORD" \
	MIGRATIONS_TABLE="${MIGRATIONS_TABLE:-schema_migrations}" \
	"$migrate_script"
fi

log "Running quick syntax checks"
php -l "$TARGET_DIR/eve-web/public/index.php" >/dev/null
python3 -m compileall "$TARGET_DIR/config_scripts" >/dev/null

if [[ "$RESTART_SERVICES" -eq 1 ]]; then
	if systemctl is-active --quiet apache2; then
		restart_service_if_present "apache2.service" "reload"
	fi
	if systemctl is-active --quiet eve-labtasks.service; then
		restart_service_if_present "eve-labtasks.service" "restart"
	fi
fi

log "Update completed successfully"
if [[ -n "$backup_file" ]]; then
	log "Backup file: $backup_file"
fi

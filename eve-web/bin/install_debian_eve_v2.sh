#!/bin/bash
set -euo pipefail

TARGET_DIR="${TARGET_DIR:-/opt/unetlab}"
REPO_URL="${REPO_URL:-https://github.com/laaaaiiit/eve-ktk.git}"
REPO_BRANCH="${REPO_BRANCH:-main}"
SERVER_NAME="${SERVER_NAME:-_}"
WEB_USER="${WEB_USER:-www-data}"
WEB_GROUP="${WEB_GROUP:-www-data}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"
DB_NAME="${DB_NAME:-eve-ng-db}"
DB_USER="${DB_USER:-eve-ng-ktk}"
DB_PASSWORD="${DB_PASSWORD:-}"
ADMIN_USERNAME="${ADMIN_USERNAME:-admin}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-}"
DB_SEARCH_PATH="${DB_SEARCH_PATH:-auth,infra,labs,runtime,checks,public}"
QEMU_VERSIONS="${QEMU_VERSIONS:-1.3.1 2.0.2 2.2.0 2.4.0 2.5.0 2.6.2 2.12.0 3.1.0 4.1.0 5.2.0 6.0.0 7.2.9 8.2.1}"
QEMU_DEFAULT_LINK="${QEMU_DEFAULT_LINK:-2.4.0}"
INSTALL_EVE_QEMU_PACKAGE="${INSTALL_EVE_QEMU_PACKAGE:-0}"
NGINX_SITE_PATH="${NGINX_SITE_PATH:-/etc/nginx/sites-available/eve-v2.conf}"
DISABLE_APACHE="${DISABLE_APACHE:-1}"
APPLY_SYSCTL="${APPLY_SYSCTL:-1}"
ENABLE_KSM="${ENABLE_KSM:-1}"
KSM_PAGES_TO_SCAN="${KSM_PAGES_TO_SCAN:-1250}"
KSM_SLEEP_MILLISECS="${KSM_SLEEP_MILLISECS:-10}"
ADMIN_USER_CREATED=0
ADMIN_PASSWORD_GENERATED=0
ADMIN_USER_INFO="not_checked"

SCRIPT_NAME="$(basename "$0")"

log() {
	echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"
}

warn() {
	echo "[$(date '+%Y-%m-%d %H:%M:%S')] WARN: $*" >&2
}

fail() {
	echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: $*" >&2
	exit 1
}

usage() {
	cat <<USAGE
Usage:
  sudo $SCRIPT_NAME [options]

Options:
  --repo <url>             Git repository URL (default: $REPO_URL)
  --branch <name>          Git branch (default: $REPO_BRANCH)
  --target-dir <path>      Install path (default: $TARGET_DIR)
  --server-name <name>     Nginx server_name (default: $SERVER_NAME)
  --db-host <host>         DB host (default: $DB_HOST)
  --db-port <port>         DB port (default: $DB_PORT)
  --db-name <name>         DB name (default: $DB_NAME)
  --db-user <user>         DB user (default: $DB_USER)
  --db-password <pass>     DB password (default: generated)
  --install-eve-qemu       Try apt install eve-ng-qemu (if repository is configured)
  --keep-apache            Do not stop/disable apache2
  --skip-sysctl            Do not apply sysctl tuning
  --skip-ksm               Do not configure KSM
  -h, --help               Show this help

Environment overrides:
  TARGET_DIR, REPO_URL, REPO_BRANCH, SERVER_NAME,
  DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD, DB_SEARCH_PATH,
  ADMIN_USERNAME, ADMIN_PASSWORD,
  QEMU_VERSIONS, QEMU_DEFAULT_LINK, INSTALL_EVE_QEMU_PACKAGE,
  WEB_USER, WEB_GROUP, DISABLE_APACHE, APPLY_SYSCTL, NGINX_SITE_PATH,
  ENABLE_KSM, KSM_PAGES_TO_SCAN, KSM_SLEEP_MILLISECS
USAGE
}

require_root() {
	[[ "$EUID" -eq 0 ]] || fail "Run as root"
}

require_cmd() {
	local cmd="$1"
	command -v "$cmd" >/dev/null 2>&1 || fail "Command not found: $cmd"
}

ensure_db_ident() {
	local value="$1"
	local field="$2"
	if [[ ! "$value" =~ ^[A-Za-z0-9_-]{1,63}$ ]]; then
		fail "$field contains unsupported characters: $value"
	fi
}

ensure_admin_username_ident() {
	local value="$1"
	if [[ ! "$value" =~ ^[A-Za-z0-9._-]{1,64}$ ]]; then
		fail "ADMIN_USERNAME contains unsupported characters: $value"
	fi
}

sql_escape_literal() {
	printf '%s' "$1" | sed "s/'/''/g"
}

random_password() {
	if command -v openssl >/dev/null 2>&1; then
		openssl rand -base64 36 | tr -dc 'A-Za-z0-9' | head -c 28
		return
	fi
	head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 28
}

set_env_value() {
	local file="$1"
	local key="$2"
	local value="$3"
	local escaped=""
	escaped="$(printf '%s' "$value" | sed -e 's/[\\/&|]/\\&/g')"
	if grep -qE "^${key}=" "$file"; then
		sed -i "s|^${key}=.*|${key}=${escaped}|" "$file"
	else
		echo "${key}=${value}" >> "$file"
	fi
}

parse_args() {
	while [[ $# -gt 0 ]]; do
		case "$1" in
			--repo)
				[[ $# -ge 2 ]] || fail "--repo requires value"
				REPO_URL="$2"
				shift 2
				;;
			--branch)
				[[ $# -ge 2 ]] || fail "--branch requires value"
				REPO_BRANCH="$2"
				shift 2
				;;
			--target-dir)
				[[ $# -ge 2 ]] || fail "--target-dir requires value"
				TARGET_DIR="$2"
				shift 2
				;;
			--server-name)
				[[ $# -ge 2 ]] || fail "--server-name requires value"
				SERVER_NAME="$2"
				shift 2
				;;
			--db-host)
				[[ $# -ge 2 ]] || fail "--db-host requires value"
				DB_HOST="$2"
				shift 2
				;;
			--db-port)
				[[ $# -ge 2 ]] || fail "--db-port requires value"
				DB_PORT="$2"
				shift 2
				;;
			--db-name)
				[[ $# -ge 2 ]] || fail "--db-name requires value"
				DB_NAME="$2"
				shift 2
				;;
			--db-user)
				[[ $# -ge 2 ]] || fail "--db-user requires value"
				DB_USER="$2"
				shift 2
				;;
			--db-password)
				[[ $# -ge 2 ]] || fail "--db-password requires value"
				DB_PASSWORD="$2"
				shift 2
				;;
			--install-eve-qemu)
				INSTALL_EVE_QEMU_PACKAGE=1
				shift
				;;
			--keep-apache)
				DISABLE_APACHE=0
				shift
				;;
			--skip-sysctl)
				APPLY_SYSCTL=0
				shift
				;;
			--skip-ksm)
				ENABLE_KSM=0
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
}

install_packages() {
	log "Installing Debian packages"
	export DEBIAN_FRONTEND=noninteractive
	apt-get update
	apt-get install -y --no-install-recommends \
		ca-certificates \
		curl \
		git \
		rsync \
		tar \
		jq \
		sudo \
		nginx \
		iproute2 \
		util-linux \
		websockify \
		postgresql \
		postgresql-client \
		postgresql-contrib \
		php-cli \
		php-fpm \
		php-pgsql \
		php-yaml \
		php-curl \
		php-xml \
		php-mbstring \
		python3 \
		python3-venv \
		qemu-system-x86 \
		qemu-utils \
		pigz \
		vpcs
}

sync_repo() {
	local target_parent
	target_parent="$(dirname "$TARGET_DIR")"
	mkdir -p "$target_parent"

	if [[ -d "$TARGET_DIR/.git" ]]; then
		log "Updating existing git repository at $TARGET_DIR"
		git -C "$TARGET_DIR" fetch origin
		git -C "$TARGET_DIR" checkout "$REPO_BRANCH"
		git -C "$TARGET_DIR" pull --ff-only origin "$REPO_BRANCH"
	elif [[ -d "$TARGET_DIR" ]] && [[ -n "$(ls -A "$TARGET_DIR" 2>/dev/null || true)" ]]; then
		fail "Target directory exists and is not a git checkout: $TARGET_DIR"
	else
		log "Cloning repository to $TARGET_DIR"
		rm -rf "$TARGET_DIR"
		git clone --branch "$REPO_BRANCH" --single-branch "$REPO_URL" "$TARGET_DIR"
	fi

	[[ -d "$TARGET_DIR/eve-web" ]] || fail "Missing expected path: $TARGET_DIR/eve-web"
}

prepare_runtime_dirs() {
	log "Preparing runtime directories"
	install -d -m 2775 -o root -g "$WEB_GROUP" "$TARGET_DIR/addons"
	install -d -m 2775 -o root -g "$WEB_GROUP" "$TARGET_DIR/addons/qemu"
	install -d -m 2775 -o root -g "$WEB_GROUP" "$TARGET_DIR/data"
	install -d -m 2775 -o root -g "$WEB_GROUP" "$TARGET_DIR/data/Logs"
	install -d -m 2775 -o root -g "$WEB_GROUP" "$TARGET_DIR/data/tmp"
	install -d -m 2775 -o root -g "$WEB_GROUP" "$TARGET_DIR/data/backups"
	install -d -m 2775 -o root -g "$WEB_GROUP" "$TARGET_DIR/data/backups/update"
	install -d -m 2775 -o root -g "$WEB_GROUP" "$TARGET_DIR/data/v2-runtime"
	install -d -m 2775 -o root -g "$WEB_GROUP" "$TARGET_DIR/data/v2-console"
	install -d -m 2775 -o root -g "$WEB_GROUP" "$TARGET_DIR/data/v2-console/sessions"
	install -d -m 2775 -o root -g "$WEB_GROUP" "$TARGET_DIR/data/v2-collab"

	if [[ ! -f "$TARGET_DIR/data/Logs/task_worker.service.log" ]]; then
		touch "$TARGET_DIR/data/Logs/task_worker.service.log"
	fi
	chown root:"$WEB_GROUP" "$TARGET_DIR/data/Logs/task_worker.service.log"
	chmod 0664 "$TARGET_DIR/data/Logs/task_worker.service.log"
}

ensure_kvm_access() {
	log "Checking KVM access for ${WEB_USER}"

	if ! id "$WEB_USER" >/dev/null 2>&1; then
		warn "User ${WEB_USER} does not exist yet, skipping KVM group setup"
		return
	fi

	if ! getent group kvm >/dev/null 2>&1; then
		warn "Group 'kvm' not found. QEMU may run without acceleration."
		return
	fi

	if id -nG "$WEB_USER" | tr ' ' '\n' | grep -qx "kvm"; then
		log "${WEB_USER} is already in group kvm"
	else
		usermod -a -G kvm "$WEB_USER"
		log "Added ${WEB_USER} to group kvm"
	fi

	if [[ ! -e /dev/kvm ]]; then
		warn "/dev/kvm is missing. Hardware virtualization may be unavailable (QEMU will use TCG, high CPU)."
		return
	fi

	if ! sudo -u "$WEB_USER" test -r /dev/kvm || ! sudo -u "$WEB_USER" test -w /dev/kvm; then
		warn "${WEB_USER} cannot read/write /dev/kvm yet. Restart php-fpm after install and verify permissions."
	fi
}

vpcs_version_string() {
	local bin="$1"
	[[ -x "$bin" ]] || { echo ""; return 0; }
	"$bin" -v 2>&1 | awk '
		tolower($0) ~ /version[[:space:]]+/ {
			line=$0
			sub(/^.*[Vv]ersion[[:space:]]+/, "", line)
			gsub(/\r/, "", line)
			print line
			exit
		}
	'
}

vpcs_version_is_known_bad() {
	local version="${1:-}"
	version="$(echo "$version" | tr '[:upper:]' '[:lower:]')"
	[[ "$version" == *"0.5b2"* ]]
}

ensure_vpcs_binary_is_usable_or_fail() {
	local path="$1"
	local version=""
	if [[ ! -x "$path" ]]; then
		fail "VPCS binary is missing at $path"
	fi
	version="$(vpcs_version_string "$path")"
	if vpcs_version_is_known_bad "$version"; then
		fail "Unsupported VPCS binary \"$version\" at $path. Install script could not replace old 0.5b2 build."
	fi
	log "Verified VPCS binary: $path (${version:-unknown})"
}

build_vpcs_from_source() {
	local tmp_dir archive src_dir built_version
	tmp_dir="$(mktemp -d /tmp/eve-vpcs-build.XXXXXX)"
	archive="$tmp_dir/vpcs.tar.gz"
	src_dir=""
	built_version=""

	log "Building fresh VPCS from source (GNS3/vpcs)"
	apt-get install -y --no-install-recommends build-essential ca-certificates curl tar >/dev/null 2>&1 || {
		warn "Failed to install build deps for VPCS source build"
		rm -rf "$tmp_dir"
		return 1
	}

	if ! curl -fsSL --connect-timeout 20 --max-time 300 \
		"https://codeload.github.com/GNS3/vpcs/tar.gz/refs/heads/master" \
		-o "$archive"; then
		warn "Failed to download VPCS source archive from GitHub"
		rm -rf "$tmp_dir"
		return 1
	fi

	if ! tar -xzf "$archive" -C "$tmp_dir"; then
		warn "Failed to unpack VPCS source archive"
		rm -rf "$tmp_dir"
		return 1
	fi

	src_dir="$(find "$tmp_dir" -maxdepth 2 -type d -name 'vpcs-*' | head -n1 || true)"
	if [[ -z "$src_dir" ]] || [[ ! -d "$src_dir/src" ]]; then
		warn "Unexpected VPCS source layout after extract"
		rm -rf "$tmp_dir"
		return 1
	fi

	if ! make -C "$src_dir/src" >/dev/null 2>&1; then
		warn "VPCS source build failed"
		rm -rf "$tmp_dir"
		return 1
	fi

	install -d -m 0755 /opt/vpcsu/bin
	if ! install -m 0755 "$src_dir/src/vpcs" /opt/vpcsu/bin/vpcs; then
		warn "Failed to install built VPCS to /opt/vpcsu/bin/vpcs"
		rm -rf "$tmp_dir"
		return 1
	fi

	built_version="$(vpcs_version_string /opt/vpcsu/bin/vpcs)"
	if vpcs_version_is_known_bad "$built_version"; then
		warn "Built VPCS version is still old (${built_version:-unknown})"
		rm -rf "$tmp_dir"
		return 1
	fi

	log "Installed VPCS from source: /opt/vpcsu/bin/vpcs (${built_version:-unknown})"
	rm -rf "$tmp_dir"
	return 0
}

ensure_vpcs_binary() {
	local existing_vpcs repo_vpcs system_vpcs existing_version backup_path
	local selected_vpcs selected_version candidate candidate_version
	existing_vpcs="/opt/vpcsu/bin/vpcs"
	repo_vpcs="$TARGET_DIR/runtime/bin/vpcs/linux-amd64/vpcs"
	system_vpcs=""
	selected_vpcs=""
	selected_version=""

	if [[ -x "$existing_vpcs" ]]; then
		existing_version="$(vpcs_version_string "$existing_vpcs")"
		if vpcs_version_is_known_bad "$existing_version"; then
			warn "Detected old VPCS build at $existing_vpcs (${existing_version:-unknown}); trying to replace with newer binary"
		else
			log "Using existing VPCS binary: $existing_vpcs (${existing_version:-unknown})"
			return
		fi
	fi

	if command -v vpcs >/dev/null 2>&1; then
		system_vpcs="$(command -v vpcs)"
	fi

	if [[ -z "$system_vpcs" ]]; then
		if apt-cache show vpcs >/dev/null 2>&1; then
			apt-get install -y vpcs || true
			if command -v vpcs >/dev/null 2>&1; then
				system_vpcs="$(command -v vpcs)"
			fi
		fi
	fi

	for candidate in "$repo_vpcs" "$system_vpcs"; do
		[[ -n "${candidate:-}" ]] || continue
		[[ -x "$candidate" ]] || continue
		candidate_version="$(vpcs_version_string "$candidate")"
		if vpcs_version_is_known_bad "$candidate_version"; then
			warn "Skipping old VPCS binary at $candidate (${candidate_version:-unknown})"
			continue
		fi
		selected_vpcs="$candidate"
		selected_version="$candidate_version"
		break
	done

	if [[ -z "$selected_vpcs" ]]; then
		warn "No compatible VPCS binary found in repo/system; trying source build"
		if build_vpcs_from_source; then
			selected_vpcs="$existing_vpcs"
			selected_version="$(vpcs_version_string "$selected_vpcs")"
		fi
	fi

	if [[ -n "$selected_vpcs" ]]; then
		if [[ "$selected_vpcs" == "$existing_vpcs" ]]; then
			chmod 0755 "$existing_vpcs" || true
			ensure_vpcs_binary_is_usable_or_fail "$existing_vpcs"
			return
		fi
		if [[ -e "$existing_vpcs" ]] && [[ ! -L "$existing_vpcs" ]]; then
			backup_path="/opt/vpcsu/bin/vpcs.backup.$(date +%Y%m%d%H%M%S)"
			cp -f "$existing_vpcs" "$backup_path" || true
			warn "Backed up previous VPCS binary to $backup_path"
		fi
		install -d -m 0755 /opt/vpcsu/bin
		ln -sfn "$selected_vpcs" "$existing_vpcs"
		log "Linked VPCS binary: $existing_vpcs -> $selected_vpcs (${selected_version:-unknown})"
		ensure_vpcs_binary_is_usable_or_fail "$existing_vpcs"
		return
	fi

	if [[ -x "$existing_vpcs" ]]; then
		existing_version="$(vpcs_version_string "$existing_vpcs")"
		if vpcs_version_is_known_bad "$existing_version"; then
			fail "VPCS remains old at $existing_vpcs (${existing_version:-unknown}) after all installer attempts."
		else
			log "Using existing VPCS binary: $existing_vpcs (${existing_version:-unknown})"
			ensure_vpcs_binary_is_usable_or_fail "$existing_vpcs"
			return
		fi
	else
		fail "VPCS binary is missing ($existing_vpcs)."
	fi
}

setup_database() {
	local esc_user esc_pass esc_db
	log "Configuring PostgreSQL"
	systemctl enable --now postgresql

	esc_user="$(sql_escape_literal "$DB_USER")"
	esc_pass="$(sql_escape_literal "$DB_PASSWORD")"
	esc_db="$(sql_escape_literal "$DB_NAME")"

	if ! sudo -u postgres psql -d postgres -tAc "SELECT 1 FROM pg_roles WHERE rolname='${esc_user}'" | grep -q 1; then
		log "Creating DB role: $DB_USER"
		sudo -u postgres psql -d postgres -v ON_ERROR_STOP=1 \
			-c "CREATE ROLE \"$DB_USER\" LOGIN PASSWORD '${esc_pass}';"
	else
		log "Updating password for DB role: $DB_USER"
		sudo -u postgres psql -d postgres -v ON_ERROR_STOP=1 \
			-c "ALTER ROLE \"$DB_USER\" WITH LOGIN PASSWORD '${esc_pass}';"
	fi

	if ! sudo -u postgres psql -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname='${esc_db}'" | grep -q 1; then
		log "Creating DB: $DB_NAME"
		sudo -u postgres psql -d postgres -v ON_ERROR_STOP=1 \
			-c "CREATE DATABASE \"$DB_NAME\" OWNER \"$DB_USER\";"
	else
		log "DB already exists: $DB_NAME"
		sudo -u postgres psql -d postgres -v ON_ERROR_STOP=1 \
			-c "ALTER DATABASE \"$DB_NAME\" OWNER TO \"$DB_USER\";"
	fi
}

prepare_env() {
	local env_file env_example
	env_file="$TARGET_DIR/eve-web/.env"
	env_example="$TARGET_DIR/eve-web/.env.example"
	if [[ ! -f "$env_file" ]]; then
		[[ -f "$env_example" ]] || fail "Missing env example: $env_example"
		cp "$env_example" "$env_file"
	fi

	set_env_value "$env_file" "DB_HOST" "$DB_HOST"
	set_env_value "$env_file" "DB_PORT" "$DB_PORT"
	set_env_value "$env_file" "DB_NAME" "$DB_NAME"
	set_env_value "$env_file" "DB_USER" "$DB_USER"
	set_env_value "$env_file" "DB_PASSWORD" "$DB_PASSWORD"
	set_env_value "$env_file" "DB_SEARCH_PATH" "$DB_SEARCH_PATH"
}

apply_migrations() {
	log "Applying DB migrations"
	DB_HOST="$DB_HOST" \
	DB_PORT="$DB_PORT" \
	DB_NAME="$DB_NAME" \
	DB_USER="$DB_USER" \
	DB_PASSWORD="$DB_PASSWORD" \
	MIGRATIONS_TABLE="schema_migrations" \
	"$TARGET_DIR/eve-web/bin/apply_migrations.sh"
}

generate_password_hash() {
	local password="$1"
	php -r '
		$password = (string) ($argv[1] ?? "");
		if ($password === "") { exit(1); }
		if (defined("PASSWORD_ARGON2ID")) {
			$hash = password_hash($password, PASSWORD_ARGON2ID, [
				"memory_cost" => 65536,
				"time_cost" => 2,
				"threads" => 1,
			]);
			if (is_string($hash) && $hash !== "") {
				echo $hash;
				exit(0);
			}
		}
		$hash = password_hash($password, PASSWORD_BCRYPT);
		if (!is_string($hash) || $hash === "") { exit(1); }
		echo $hash;
	' -- "$password"
}

ensure_initial_admin_user() {
	local conn schema users_count users_count_trim admin_role_id password_hash
	local esc_username esc_password_hash esc_role_id

	conn="host=$DB_HOST port=$DB_PORT dbname=$DB_NAME user=$DB_USER"
	schema="$(PGPASSWORD="$DB_PASSWORD" psql "$conn" -At -v ON_ERROR_STOP=1 -c \
		"SELECT CASE
			WHEN to_regclass('auth.users') IS NOT NULL AND to_regclass('auth.roles') IS NOT NULL THEN 'auth'
			WHEN to_regclass('public.users') IS NOT NULL AND to_regclass('public.roles') IS NOT NULL THEN 'public'
			ELSE ''
		END")"

	if [[ -z "$schema" ]]; then
		ADMIN_USER_INFO="skipped:users_table_not_found"
		log "Skipping first admin creation: users table not found"
		return
	fi

	users_count="$(PGPASSWORD="$DB_PASSWORD" psql "$conn" -At -v ON_ERROR_STOP=1 -c "SELECT COUNT(*) FROM ${schema}.users")"
	users_count_trim="${users_count//[[:space:]]/}"
	if [[ "$users_count_trim" =~ ^[0-9]+$ ]] && (( users_count_trim > 0 )); then
		ADMIN_USER_INFO="skipped:users_exist(${users_count_trim})"
		log "Skipping first admin creation: users table already has ${users_count_trim} records"
		return
	fi

	if [[ "$schema" == "auth" ]]; then
		admin_role_id="$(PGPASSWORD="$DB_PASSWORD" psql "$conn" -At -v ON_ERROR_STOP=1 -c "SELECT id FROM auth.roles WHERE lower(name)='admin' ORDER BY id LIMIT 1")"
	else
		admin_role_id="$(PGPASSWORD="$DB_PASSWORD" psql "$conn" -At -v ON_ERROR_STOP=1 -c "SELECT id FROM public.roles WHERE lower(name)='admin' ORDER BY id LIMIT 1")"
	fi
	admin_role_id="${admin_role_id//[[:space:]]/}"
	[[ -n "$admin_role_id" ]] || fail "Failed to create first admin user: admin role not found"

	if [[ -z "$ADMIN_PASSWORD" ]]; then
		ADMIN_PASSWORD="$(random_password)"
		ADMIN_PASSWORD_GENERATED=1
	fi

	password_hash="$(generate_password_hash "$ADMIN_PASSWORD")" || fail "Failed to hash admin password"
	esc_username="$(sql_escape_literal "$ADMIN_USERNAME")"
	esc_password_hash="$(sql_escape_literal "$password_hash")"
	esc_role_id="$(sql_escape_literal "$admin_role_id")"

	if [[ "$schema" == "auth" ]]; then
		PGPASSWORD="$DB_PASSWORD" psql "$conn" -v ON_ERROR_STOP=1 \
			-c "INSERT INTO auth.users (username, password_hash, role_id, is_blocked, lang, theme)
			    VALUES ('${esc_username}', '${esc_password_hash}', '${esc_role_id}', FALSE, 'en', 'dark')" >/dev/null
	else
		PGPASSWORD="$DB_PASSWORD" psql "$conn" -v ON_ERROR_STOP=1 \
			-c "INSERT INTO public.users (username, password_hash, role_id, is_blocked, lang, theme)
			    VALUES ('${esc_username}', '${esc_password_hash}', '${esc_role_id}', FALSE, 'en', 'dark')" >/dev/null
	fi

	ADMIN_USER_CREATED=1
	ADMIN_USER_INFO="created:${ADMIN_USERNAME}"
	log "First admin user created: ${ADMIN_USERNAME}"
}

install_qemu_versions_layout() {
	local qemu_bin qemu_img version qemu_dir qemu_target img_target first_version
	first_version=""

	log "Configuring QEMU version layout in /opt/qemu-*"

	if [[ "$INSTALL_EVE_QEMU_PACKAGE" == "1" ]]; then
		if apt-cache policy eve-ng-qemu 2>/dev/null | grep -q 'Candidate: (none)'; then
			warn "eve-ng-qemu package is not available in configured APT repositories"
		else
			apt-get install -y eve-ng-qemu || warn "Failed to install eve-ng-qemu package, continue with compatibility layout"
		fi
	fi

	qemu_bin=""
	for qemu_bin in \
		/usr/bin/qemu-system-x86_64 \
		/opt/qemu/bin/qemu-system-x86_64 \
		/usr/libexec/qemu-kvm \
		/usr/bin/kvm; do
		if [[ -x "$qemu_bin" ]]; then
			break
		fi
		qemu_bin=""
	done
	if [[ -z "$qemu_bin" ]]; then
		log "qemu-system-x86_64 not found, trying to install fallback package qemu-system-x86"
		if apt-cache show qemu-system-x86 >/dev/null 2>&1; then
			apt-get install -y qemu-system-x86 || true
		fi
		for qemu_bin in \
			/usr/bin/qemu-system-x86_64 \
			/opt/qemu/bin/qemu-system-x86_64 \
			/usr/libexec/qemu-kvm \
			/usr/bin/kvm; do
			if [[ -x "$qemu_bin" ]]; then
				break
			fi
			qemu_bin=""
		done
	fi
	[[ -n "$qemu_bin" ]] || fail "qemu-system-x86_64 not found after package installation"

	qemu_img=""
	for qemu_img in /usr/bin/qemu-img /opt/qemu/bin/qemu-img; do
		if [[ -x "$qemu_img" ]]; then
			break
		fi
		qemu_img=""
	done

	for version in $QEMU_VERSIONS; do
		qemu_dir="/opt/qemu-${version}/bin"
		if [[ -z "$first_version" ]]; then
			first_version="$version"
		fi
		install -d -m 0755 "$qemu_dir"

		qemu_target="${qemu_dir}/qemu-system-x86_64"
		if [[ ! -e "$qemu_target" || ( -L "$qemu_target" && ! -e "$qemu_target" ) ]]; then
			ln -sfn "$qemu_bin" "$qemu_target"
		fi

		if [[ -n "$qemu_img" ]]; then
			img_target="${qemu_dir}/qemu-img"
			if [[ ! -e "$img_target" || ( -L "$img_target" && ! -e "$img_target" ) ]]; then
				ln -sfn "$qemu_img" "$img_target"
			fi
		fi
	done

	if [[ -d "/opt/qemu-${QEMU_DEFAULT_LINK}" ]]; then
		ln -sfn "qemu-${QEMU_DEFAULT_LINK}" /opt/qemu
	elif [[ -n "$first_version" && -d "/opt/qemu-${first_version}" ]]; then
		ln -sfn "qemu-${first_version}" /opt/qemu
	fi
}

install_sudoers() {
	local sudoers_path php_bins=()
	sudoers_path="/etc/sudoers.d/eve-v2-runtime"
	log "Installing sudoers rules for runtime"

	if [[ -x /usr/bin/php ]]; then
		php_bins+=(/usr/bin/php)
	fi
	while IFS= read -r php_bin; do
		[[ -x "$php_bin" ]] || continue
		php_bins+=("$php_bin")
	done < <(find /usr/bin -maxdepth 1 -type f -name 'php[0-9]*' -print 2>/dev/null | sort -u)

	{
		echo "Defaults:${WEB_USER} !requiretty"
		echo "${WEB_USER} ALL=(root) NOPASSWD: /usr/sbin/ip, /usr/sbin/ip *, /sbin/ip, /sbin/ip *, /usr/bin/ip, /usr/bin/ip *, /bin/ip, /bin/ip *"
		for php_bin in "${php_bins[@]}"; do
			echo "${WEB_USER} ALL=(root) NOPASSWD: ${php_bin} ${TARGET_DIR}/eve-web/bin/run_host_console_session.php --session=*"
			echo "${WEB_USER} ALL=(root) NOPASSWD: ${php_bin} ${TARGET_DIR}/eve-web/bin/host_upload_finalize.php --tmp=* --dest=* --overwrite=*"
			echo "${WEB_USER} ALL=(root) NOPASSWD: ${php_bin} ${TARGET_DIR}/eve-web/bin/host_list_files.php --path=* --limit=*"
			echo "${WEB_USER} ALL=(root) NOPASSWD: ${php_bin} ${TARGET_DIR}/eve-web/bin/host_stream_download.php --path=* --meta=1"
			echo "${WEB_USER} ALL=(root) NOPASSWD: ${php_bin} ${TARGET_DIR}/eve-web/bin/host_stream_download.php --path=* --offset=* --length=*"
			echo "${WEB_USER} ALL=(root) NOPASSWD: ${php_bin} ${TARGET_DIR}/eve-web/bin/host_stream_capture.php --if=*"
			echo "${WEB_USER} ALL=(root) NOPASSWD: ${php_bin} ${TARGET_DIR}/eve-web/bin/host_stream_capture.php *"
			echo "${WEB_USER} ALL=(root) NOPASSWD: ${php_bin} ${TARGET_DIR}/eve-web/bin/host_stream_capture_packets.php *"
		done
	} > "$sudoers_path"

	chmod 0440 "$sudoers_path"
	visudo -c -f "$sudoers_path" >/dev/null
}

install_labtasks_service() {
	local service_path
	service_path="/etc/systemd/system/eve-labtasks.service"
	log "Installing systemd service: eve-labtasks"

	cat > "$service_path" <<SERVICE
[Unit]
Description=EVE-NG Lab Tasks Worker Pool
After=network-online.target postgresql.service
Wants=network-online.target

[Service]
Type=simple
User=root
Group=root
WorkingDirectory=${TARGET_DIR}/eve-web
ExecStart=${TARGET_DIR}/eve-web/bin/run_lab_tasks_pool.sh
Restart=always
RestartSec=1
StandardOutput=append:${TARGET_DIR}/data/Logs/task_worker.service.log
StandardError=append:${TARGET_DIR}/data/Logs/task_worker.service.log
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
SERVICE

	systemctl daemon-reload
	systemctl enable --now eve-labtasks.service
}

detect_php_fpm_service() {
	local service
	service="$({ systemctl list-unit-files --type=service --no-legend 2>/dev/null || true; } | awk '/^php[0-9]+\.[0-9]+-fpm\.service/ {print $1}' | sort -V | tail -n1)"
	if [[ -z "$service" ]]; then
		if systemctl list-unit-files --type=service --no-legend 2>/dev/null | awk '{print $1}' | grep -qx 'php-fpm.service'; then
			service='php-fpm.service'
		fi
	fi
	echo "$service"
}

detect_php_fpm_socket() {
	local service="$1"
	local socket=""
	if [[ -n "$service" ]]; then
		socket="/run/php/${service%.service}.sock"
		if [[ -S "$socket" ]]; then
			echo "$socket"
			return
		fi
	fi
	socket="$(find /run/php -maxdepth 1 -type s -name 'php*-fpm.sock' 2>/dev/null | sort -V | tail -n1 || true)"
	echo "$socket"
}

configure_nginx() {
	local php_fpm_service php_fpm_socket
	log "Configuring nginx"

	php_fpm_service="$(detect_php_fpm_service)"
	[[ -n "$php_fpm_service" ]] || fail "Unable to detect php-fpm service"
	systemctl enable --now "$php_fpm_service"
	php_fpm_socket="$(detect_php_fpm_socket "$php_fpm_service")"
	[[ -n "$php_fpm_socket" ]] || fail "Unable to detect php-fpm socket"

	cat > "$NGINX_SITE_PATH" <<NGINX
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name ${SERVER_NAME};

    root ${TARGET_DIR}/eve-web/public;
    index index.php index.html;

    client_max_body_size 25G;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_read_timeout 600s;
        fastcgi_send_timeout 600s;
        fastcgi_pass unix:${php_fpm_socket};
    }

    location /vncws/ {
        proxy_http_version 1.1;
        proxy_pass http://127.0.0.1:6080/;
        proxy_set_header Host \$host;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }

    location /collabws/ {
        proxy_http_version 1.1;
        proxy_pass http://127.0.0.1:6090/;
        proxy_set_header Host \$host;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX

	ln -sf "$NGINX_SITE_PATH" /etc/nginx/sites-enabled/eve-v2.conf
	if [[ -L /etc/nginx/sites-enabled/default ]]; then
		rm -f /etc/nginx/sites-enabled/default
	fi

	if [[ "$DISABLE_APACHE" == "1" ]]; then
		if systemctl list-unit-files --type=service --no-legend 2>/dev/null | awk '{print $1}' | grep -qx 'apache2.service'; then
			if systemctl is-enabled --quiet apache2 2>/dev/null; then
				systemctl disable apache2 || true
			fi
			if systemctl is-active --quiet apache2 2>/dev/null; then
				systemctl stop apache2 || true
			fi
		fi
	fi

	nginx -t
	systemctl enable --now nginx
	systemctl reload nginx
}

apply_sysctl_tuning() {
	local sysctl_file
	[[ "$APPLY_SYSCTL" == "1" ]] || {
		log "Skipping sysctl tuning by option"
		return
	}

	sysctl_file="/etc/sysctl.d/99-eve-v2.conf"
	log "Applying sysctl tuning"
	cat > "$sysctl_file" <<SYSCTL
net.ipv4.ip_forward = 1
net.ipv4.conf.all.rp_filter = 0
net.ipv4.conf.default.rp_filter = 0
fs.inotify.max_user_watches = 524288
fs.inotify.max_user_instances = 2048
vm.swappiness = 10
SYSCTL
	sysctl --system >/dev/null || warn "sysctl --system returned non-zero status"
}

configure_ksm() {
	local ksm_service ksm_script
	[[ "$ENABLE_KSM" == "1" ]] || {
		log "Skipping KSM configuration by option"
		return
	}

	if [[ ! -e /sys/kernel/mm/ksm/run ]]; then
		warn "KSM sysfs is unavailable on this kernel: /sys/kernel/mm/ksm/run"
		return
	fi

	if [[ ! "$KSM_PAGES_TO_SCAN" =~ ^[0-9]+$ ]] || [[ ! "$KSM_SLEEP_MILLISECS" =~ ^[0-9]+$ ]]; then
		fail "KSM_PAGES_TO_SCAN and KSM_SLEEP_MILLISECS must be numeric"
	fi

	ksm_script="/usr/local/sbin/eve-ksm-apply.sh"
	ksm_service="/etc/systemd/system/eve-ksm.service"

	log "Configuring KSM (pages_to_scan=${KSM_PAGES_TO_SCAN}, sleep_millisecs=${KSM_SLEEP_MILLISECS})"

	cat > "$ksm_script" <<KSM_SCRIPT
#!/bin/bash
set -euo pipefail
if [[ -w /sys/kernel/mm/ksm/pages_to_scan ]]; then
	echo ${KSM_PAGES_TO_SCAN} > /sys/kernel/mm/ksm/pages_to_scan
fi
if [[ -w /sys/kernel/mm/ksm/sleep_millisecs ]]; then
	echo ${KSM_SLEEP_MILLISECS} > /sys/kernel/mm/ksm/sleep_millisecs
fi
if [[ -w /sys/kernel/mm/ksm/run ]]; then
	echo 1 > /sys/kernel/mm/ksm/run
fi
KSM_SCRIPT
	chmod 0755 "$ksm_script"

	cat > "$ksm_service" <<KSM_SERVICE
[Unit]
Description=Apply EVE KSM tuning
After=local-fs.target

[Service]
Type=oneshot
ExecStart=${ksm_script}
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
KSM_SERVICE

	systemctl daemon-reload || warn "systemctl daemon-reload returned non-zero status for KSM"
	systemctl enable --now eve-ksm.service || warn "Failed to enable/start eve-ksm.service"

	if [[ -r /sys/kernel/mm/ksm/run ]]; then
		log "KSM run=$(cat /sys/kernel/mm/ksm/run 2>/dev/null || echo '?')"
	fi
}

run_quick_checks() {
	log "Running quick checks"
	php -l "$TARGET_DIR/eve-web/public/index.php" >/dev/null
	python3 -m compileall "$TARGET_DIR/config_scripts" >/dev/null
	curl -fsS "http://127.0.0.1/login" >/dev/null || warn "HTTP check failed at /login"
}

restart_runtime_services() {
	local php_fpm_service
	log "Restarting runtime services"
	php_fpm_service="$(detect_php_fpm_service)"

	systemctl daemon-reload || warn "systemctl daemon-reload returned non-zero status"
	systemctl restart postgresql || warn "Failed to restart postgresql"
	if [[ -n "$php_fpm_service" ]]; then
		systemctl restart "$php_fpm_service" || warn "Failed to restart ${php_fpm_service}"
	else
		warn "Unable to detect php-fpm service for restart"
	fi
	systemctl restart nginx || warn "Failed to restart nginx"
	systemctl restart eve-labtasks.service || warn "Failed to restart eve-labtasks.service"
}

print_summary() {
	cat <<SUMMARY

Install complete.

Path:
  ${TARGET_DIR}

HTTP URL:
  http://$(hostname -I 2>/dev/null | awk '{print $1}')/login
  (or http://<server-ip>/login)

Database:
  host=${DB_HOST} port=${DB_PORT} db=${DB_NAME} user=${DB_USER}
  password=${DB_PASSWORD}

Admin user:
  status=${ADMIN_USER_INFO}
$(if [[ "$ADMIN_USER_CREATED" == "1" ]]; then
	printf '  username=%s\n  password=%s\n' "$ADMIN_USERNAME" "$ADMIN_PASSWORD"
fi)

Services:
  - nginx
  - $(detect_php_fpm_service)
  - postgresql
  - eve-labtasks

Next checks:
  systemctl status nginx eve-labtasks --no-pager
  tail -n 100 ${TARGET_DIR}/data/Logs/task_worker.service.log
SUMMARY
}

main() {
	parse_args "$@"
	require_root
	require_cmd apt-get
	require_cmd systemctl
	require_cmd sed
	require_cmd awk

	ensure_db_ident "$DB_NAME" "DB_NAME"
	ensure_db_ident "$DB_USER" "DB_USER"
	ensure_admin_username_ident "$ADMIN_USERNAME"
	[[ "$DB_PORT" =~ ^[0-9]+$ ]] || fail "DB_PORT must be numeric: $DB_PORT"

	if [[ -z "$DB_PASSWORD" ]]; then
		DB_PASSWORD="$(random_password)"
		log "Generated random DB password"
	fi

	install_packages
	ensure_kvm_access
	require_cmd git
	require_cmd visudo
	require_cmd curl
	sync_repo
	ensure_vpcs_binary
	install_qemu_versions_layout
	prepare_runtime_dirs
	setup_database
	prepare_env
	apply_migrations
	ensure_initial_admin_user
	install_sudoers
	install_labtasks_service
	configure_nginx
	apply_sysctl_tuning
	configure_ksm
	restart_runtime_services
	run_quick_checks
	print_summary
}

main "$@"

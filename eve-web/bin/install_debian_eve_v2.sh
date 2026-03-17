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
DB_SEARCH_PATH="${DB_SEARCH_PATH:-auth,infra,labs,runtime,checks,public}"
QEMU_VERSIONS="${QEMU_VERSIONS:-1.3.1 2.0.2 2.2.0 2.4.0 2.5.0 2.6.2 2.12.0 3.1.0 4.1.0 5.2.0 6.0.0 7.2.9 8.2.1}"
QEMU_DEFAULT_LINK="${QEMU_DEFAULT_LINK:-2.4.0}"
INSTALL_EVE_QEMU_PACKAGE="${INSTALL_EVE_QEMU_PACKAGE:-0}"
NGINX_SITE_PATH="${NGINX_SITE_PATH:-/etc/nginx/sites-available/eve-v2.conf}"
DISABLE_APACHE="${DISABLE_APACHE:-1}"
APPLY_SYSCTL="${APPLY_SYSCTL:-1}"

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
  -h, --help               Show this help

Environment overrides:
  TARGET_DIR, REPO_URL, REPO_BRANCH, SERVER_NAME,
  DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD, DB_SEARCH_PATH,
  QEMU_VERSIONS, QEMU_DEFAULT_LINK, INSTALL_EVE_QEMU_PACKAGE,
  WEB_USER, WEB_GROUP, DISABLE_APACHE, APPLY_SYSCTL, NGINX_SITE_PATH
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
		qemu-utils
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

run_quick_checks() {
	log "Running quick checks"
	php -l "$TARGET_DIR/eve-web/public/index.php" >/dev/null
	python3 -m compileall "$TARGET_DIR/config_scripts" >/dev/null
	curl -fsS "http://127.0.0.1/login" >/dev/null || warn "HTTP check failed at /login"
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
	[[ "$DB_PORT" =~ ^[0-9]+$ ]] || fail "DB_PORT must be numeric: $DB_PORT"

	if [[ -z "$DB_PASSWORD" ]]; then
		DB_PASSWORD="$(random_password)"
		log "Generated random DB password"
	fi

	install_packages
	require_cmd git
	require_cmd visudo
	require_cmd curl
	install_qemu_versions_layout
	sync_repo
	prepare_runtime_dirs
	setup_database
	prepare_env
	apply_migrations
	install_sudoers
	install_labtasks_service
	configure_nginx
	apply_sysctl_tuning
	run_quick_checks
	print_summary
}

main "$@"

#!/bin/bash
set -euo pipefail

BASE_DIR="/opt/unetlab/eve-web"
PHP_BIN="/usr/bin/php"
WORKER_SCRIPT="$BASE_DIR/bin/run_lab_tasks_once.php"
RUNTIME_SYNC_SCRIPT="$BASE_DIR/bin/sync_runtime_states_once.php"
ENV_FILE="$BASE_DIR/.env"

DB_HOST="127.0.0.1"
DB_PORT="5432"
DB_NAME="eve-ng-db"
DB_USER="eve-ng-ktk"
DB_PASSWORD=""

if [[ -f "$ENV_FILE" ]]; then
  set -a
  # shellcheck disable=SC1090
  . "$ENV_FILE"
  set +a
fi

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"
DB_NAME="${DB_NAME:-eve-ng-db}"
DB_USER="${DB_USER:-eve-ng-ktk}"
DB_PASSWORD="${DB_PASSWORD:-}"

if [[ ! -x "$PHP_BIN" ]]; then
  PHP_BIN="$(command -v php || true)"
fi
if [[ -z "$PHP_BIN" || ! -x "$PHP_BIN" ]]; then
  echo "[labtasks-pool] php binary not found" >&2
  exit 1
fi
if [[ ! -f "$WORKER_SCRIPT" ]]; then
  echo "[labtasks-pool] worker script not found: $WORKER_SCRIPT" >&2
  exit 1
fi
if [[ ! -f "$RUNTIME_SYNC_SCRIPT" ]]; then
  echo "[labtasks-pool] runtime sync script not found: $RUNTIME_SYNC_SCRIPT" >&2
  exit 1
fi

get_worker_slots_triplet() {
  local start_value stop_value check_value legacy_value
  start_value=""
  stop_value=""
  check_value=""
  if [[ -n "$DB_PASSWORD" ]] && command -v psql >/dev/null 2>&1; then
    local pair
    pair="$(PGPASSWORD="$DB_PASSWORD" psql \
      "host=$DB_HOST port=$DB_PORT dbname=$DB_NAME user=$DB_USER" \
      -Atc "select coalesce(start_worker_slots, worker_slots, 1), coalesce(stop_worker_slots, worker_slots, 1), coalesce(check_worker_slots, worker_slots, 1) from task_queue_settings where id=1;" 2>/dev/null | head -n1)"
    if [[ -n "$pair" ]]; then
      start_value="$(echo "$pair" | awk -F'|' '{print $1}' | tr -d '[:space:]')"
      stop_value="$(echo "$pair" | awk -F'|' '{print $2}' | tr -d '[:space:]')"
      check_value="$(echo "$pair" | awk -F'|' '{print $3}' | tr -d '[:space:]')"
    fi
    if [[ -z "$start_value" || -z "$stop_value" || -z "$check_value" ]]; then
      legacy_value="$(PGPASSWORD="$DB_PASSWORD" psql \
        "host=$DB_HOST port=$DB_PORT dbname=$DB_NAME user=$DB_USER" \
        -Atc "select coalesce(worker_slots,1) from task_queue_settings where id=1;" 2>/dev/null | head -n1 | tr -d '[:space:]')"
      if [[ "$legacy_value" =~ ^[0-9]+$ ]]; then
        start_value="$legacy_value"
        stop_value="$legacy_value"
        check_value="$legacy_value"
      fi
    fi
  else
    start_value=""
    stop_value=""
    check_value=""
  fi

  if ! [[ "$start_value" =~ ^[0-9]+$ ]]; then start_value=1; fi
  if ! [[ "$stop_value" =~ ^[0-9]+$ ]]; then stop_value=1; fi
  if ! [[ "$check_value" =~ ^[0-9]+$ ]]; then check_value=1; fi

  if (( start_value < 1 )); then start_value=1; fi
  if (( stop_value < 1 )); then stop_value=1; fi
  if (( check_value < 1 )); then check_value=1; fi
  if (( start_value > 32 )); then start_value=32; fi
  if (( stop_value > 32 )); then stop_value=32; fi
  if (( check_value > 32 )); then check_value=32; fi

  echo "$start_value $stop_value $check_value"
}

if [[ "${EVE_TASK_SYNC_ON_START:-1}" == "1" ]]; then
  "$PHP_BIN" "$RUNTIME_SYNC_SCRIPT" >/dev/null 2>&1 || true
fi

declare -A WORKER_PIDS=()

is_pid_alive() {
  local pid="${1:-0}"
  if ! [[ "$pid" =~ ^[0-9]+$ ]]; then
    return 1
  fi
  if (( pid <= 1 )); then
    return 1
  fi
  kill -0 "$pid" >/dev/null 2>&1
}

spawn_mode_worker() {
  local mode="$1"
  local slot="$2"
  (
    while true; do
      "$PHP_BIN" "$WORKER_SCRIPT" --mode="$mode" >/dev/null 2>&1 || true
      sleep 0.2
    done
  ) &
  WORKER_PIDS["$mode:$slot"]="$!"
}

stop_mode_worker() {
  local mode="$1"
  local slot="$2"
  local key="$mode:$slot"
  local pid="${WORKER_PIDS[$key]:-}"
  if [[ -z "$pid" ]]; then
    unset "WORKER_PIDS[$key]"
    return
  fi
  if is_pid_alive "$pid"; then
    kill "$pid" >/dev/null 2>&1 || true
    wait "$pid" 2>/dev/null || true
  fi
  unset "WORKER_PIDS[$key]"
}

reconcile_mode_workers() {
  local mode="$1"
  local desired_raw="$2"
  local desired="$desired_raw"
  if ! [[ "$desired" =~ ^[0-9]+$ ]]; then
    desired=1
  fi
  if (( desired < 1 )); then desired=1; fi
  if (( desired > 32 )); then desired=32; fi

  local key pid slot
  for key in "${!WORKER_PIDS[@]}"; do
    if [[ "$key" != "$mode:"* ]]; then
      continue
    fi
    pid="${WORKER_PIDS[$key]}"
    if ! is_pid_alive "$pid"; then
      unset "WORKER_PIDS[$key]"
    fi
  done

  for ((slot=desired + 1; slot<=32; slot++)); do
    if [[ -n "${WORKER_PIDS[$mode:$slot]:-}" ]]; then
      stop_mode_worker "$mode" "$slot"
    fi
  done

  for ((slot=1; slot<=desired; slot++)); do
    key="$mode:$slot"
    pid="${WORKER_PIDS[$key]:-}"
    if [[ -n "$pid" ]] && is_pid_alive "$pid"; then
      continue
    fi
    spawn_mode_worker "$mode" "$slot"
  done
}

cleanup_workers() {
  local key pid
  for key in "${!WORKER_PIDS[@]}"; do
    pid="${WORKER_PIDS[$key]}"
    if is_pid_alive "$pid"; then
      kill "$pid" >/dev/null 2>&1 || true
    fi
  done
  for key in "${!WORKER_PIDS[@]}"; do
    pid="${WORKER_PIDS[$key]}"
    if [[ "$pid" =~ ^[0-9]+$ ]] && (( pid > 1 )); then
      wait "$pid" 2>/dev/null || true
    fi
    unset "WORKER_PIDS[$key]"
  done
}

trap cleanup_workers EXIT INT TERM

while true; do
  read -r start_slots stop_slots check_slots <<<"$(get_worker_slots_triplet)"
  reconcile_mode_workers "start" "$start_slots"
  reconcile_mode_workers "stop" "$stop_slots"
  reconcile_mode_workers "check" "$check_slots"
  sleep 1
done

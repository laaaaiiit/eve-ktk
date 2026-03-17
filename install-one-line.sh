#!/usr/bin/env bash
set -euo pipefail

INSTALL_URL="${INSTALL_URL:-https://raw.githubusercontent.com/laaaaiiit/eve-ktk/main/eve-web/bin/install_debian_eve_v2.sh}"
TMP_SCRIPT="$(mktemp /tmp/eve-v2-install.XXXXXX.sh)"

cleanup() {
	rm -f "$TMP_SCRIPT"
}
trap cleanup EXIT

curl -fsSL "$INSTALL_URL" -o "$TMP_SCRIPT"
chmod +x "$TMP_SCRIPT"

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
	exec sudo -E bash "$TMP_SCRIPT"
else
	exec bash "$TMP_SCRIPT"
fi

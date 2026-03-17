#!/bin/bash
set -euo pipefail

TARGET_DIR="${TARGET_DIR:-/opt/unetlab}"
INSTALLER="${TARGET_DIR}/eve-web/bin/install_debian_eve_v2.sh"

if [[ ! -x "$INSTALLER" ]]; then
	echo "[update_system] ERROR: installer not found or not executable: $INSTALLER" >&2
	exit 1
fi

echo "[update_system] INFO: update_system.sh is a compatibility wrapper."
echo "[update_system] INFO: using single install/update flow via install_debian_eve_v2.sh."
exec "$INSTALLER" "$@"

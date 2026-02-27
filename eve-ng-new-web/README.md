# eve-ng-new-web

New isolated web root for EVE-NG v2 API.

## Current scope
- PostgreSQL migrations (`migrations/*.sql`)
- migration runner (`bin/apply_migrations.sh`)
- v2 auth endpoints (`public/index.php`)

## Endpoints
- `POST /api/v2/auth/login`
- `GET /api/v2/auth`
- `GET /api/v2/auth/logout`
- `POST /api/v2/auth/ping`
- `GET /api/v2/system/status`
- `GET /api/v2/system/logs`
- `GET /api/v2/tasks`
- `GET /api/v2/labs/{lab_id}/tasks`
- `POST /api/v2/labs/{lab_id}/nodes/{node_id}/power/{start|stop}`
- `POST /api/v2/labs/{lab_id}/nodes/{node_id}/console/session`
- `GET /api/v2/console/sessions/{session_id}`
- `GET /api/v2/console/sessions/{session_id}/read`
- `POST /api/v2/console/sessions/{session_id}/write`
- `DELETE /api/v2/console/sessions/{session_id}`

## Apply migrations
```bash
cd /opt/unetlab/eve-ng-new-web
DB_PASSWORD='123123' ./bin/apply_migrations.sh
```

Optional env vars: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`.

## Runtime env
Copy `.env.example` to `.env` and set real credentials.

## QEMU template note (important)
When you add a new image folder to `addons/qemu`, template start can be:
- `OK` immediately (if image and options are compatible with current runtime)
- `FAILED` (if template needs special prep logic)

Typical failure reasons:
- disk extension is `.qcow2`, but real format is `raw` (or another format)
- `qemu_options` references files that do not exist for this image
- template depends on KVM-only CPU/options and host falls back to TCG

What is already handled in v2 runtime:
- automatic disk format detection for backing files
- safer overlay creation for mixed disk formats
- filtering/normalizing extra `qemu_options` file references
- KVM error fallback (`accel=tcg`, safer CPU fallback)

If start still fails:
1. Check `lab_tasks.error_text` and node `last_error` in PostgreSQL.
2. Check runtime logs in `/opt/unetlab/data/v2-runtime/<USER_ID>/labs/<LAB_UUID>/nodes/<NODE_UUID>/qemu.log`.
3. Decide whether this template is `generic` or needs a dedicated hook/prep profile.

Recommendation for future:
- keep a template preflight/profile layer (`generic` / `needs-hook` / `unsupported`) to avoid trial-and-error when adding new images.

## Node networking note (runtime)
- `qemu`, `vpcs` and `dynamips` use wrapper-free user-space networking in v2.
- For point-to-point links (`network_endpoint_count = 2`), runtime now prefers deterministic UDP pair transport (`127.0.0.1:<portA/B>`), not TAP.
- If a port is not point-to-point (or is a cloud `pnet*`), runtime falls back to current behavior (e.g. qemu socket multicast).
- `iol` currently starts via `iol_wrapper` to keep stable telnet console behavior in v2 browser-console flow.

## Browser console note (v2)
- v2 now has a native browser console session flow (without Guacamole backend dependency): `POST /api/v2/labs/{lab_id}/nodes/{node_id}/console/session`.
- Console I/O is handled by a per-session worker (`bin/run_console_session.php`) and file-backed stream endpoints under `/api/v2/console/sessions/*`.
- Text console (`telnet`) is streamed through the session API.
- VNC is supported via local noVNC + websockify token proxy (`/v2/vncws/`) managed by `src/ConsoleService.php`.

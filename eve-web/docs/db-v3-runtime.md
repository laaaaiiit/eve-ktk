# DB v3 Runtime Layout (for current code)

This variant keeps the existing table/column contract used by PHP services,
but distributes tables across domain schemas and removes cross-domain FK coupling.

Target DB: `eve-ng-db-v3`

## Build / Rebuild
```bash
cd /opt/unetlab/eve-web
./bin/build_v3_runtime_db.sh
```

Source DB default: `eve-ng-db`
Target DB default: `eve-ng-db-v3`

Overrides:
```bash
SOURCE_DB_NAME=eve-ng-db TARGET_DB_NAME=eve-ng-db-v3 ./bin/build_v3_runtime_db.sh
```

## Domain schemas
- `auth`: `users`, `roles`, `permissions`, `role_permissions`, `auth_sessions`
- `infra`: `clouds`, `cloud_users`
- `labs`: `labs`, `lab_folders`, `lab_collab_tokens`, `lab_shared_users`, `lab_nodes`, `lab_networks`, `lab_node_ports`, `lab_objects`
- `runtime`: `lab_tasks`, `lab_task_groups`, `task_queue_settings`
- `checks`: all `lab_check_*` tables

## Search path
Application uses:
```env
DB_SEARCH_PATH=auth,infra,labs,runtime,checks,public
```

So existing SQL with unqualified table names (`FROM labs`, `FROM users`, etc.) keeps working.

## What was simplified
- Cross-domain foreign keys are dropped in `eve-ng-db-v3`.
- Intra-domain keys/indexes/triggers are preserved from the source schema.

## Smoke check
```bash
php /opt/unetlab/eve-web/tmp/v3_service_smoke.php
```

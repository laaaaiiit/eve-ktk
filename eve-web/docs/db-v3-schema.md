# DB v3 Proposal (Simplified Domains)

This is a clean prototype schema in a separate database (`eve-ng-db-v3`) for manual review.

## Why this version is simpler
- Domain split by schema: `auth`, `infra`, `labs`, `runtime`, `checks`, `ops`.
- Foreign keys are mostly inside one domain.
- Cross-domain references are plain UUID fields when strict coupling is not required.
- Support views in `ops` reduce manual JOIN complexity.

## Domain map
- `auth`: users, roles, permissions, sessions.
- `infra`: clouds and cloud access policy.
- `labs`: topology only (labs, nodes, links, endpoints, objects).
- `runtime`: workers, jobs, node runtime states, queue settings.
- `checks`: lab checks, runs/results, tasks/task marks.
- `ops`: read-only support views.

## Main design decisions
- No hard FK from `labs` to `auth.users`.
- No hard FK from `runtime` to `labs`.
- `checks` tied to check-set lifecycle, but keeps `lab_id` as UUID for decoupling.
- Topology model is linear:
  - `labs.links`
  - `labs.link_endpoints`
  This replaces many indirect topology links.

## Quick ER sketch
```mermaid
erDiagram
  auth_roles ||--o{ auth_users : has
  auth_roles ||--o{ auth_role_permissions : maps
  auth_permissions ||--o{ auth_role_permissions : maps
  auth_users ||--o{ auth_sessions : opens

  labs_labs ||--o{ labs_nodes : contains
  labs_labs ||--o{ labs_links : contains
  labs_links ||--o{ labs_link_endpoints : has
  labs_nodes ||--o{ labs_link_endpoints : anchors
  labs_labs ||--o{ labs_objects : contains
  labs_labs ||--o{ labs_members : shares

  runtime_jobs ||--o{ runtime_job_events : logs

  checks_sets ||--o{ checks_items : defines
  checks_sets ||--o{ checks_runs : executes
  checks_runs ||--o{ checks_results : returns
  checks_tasks ||--o{ checks_task_marks : tracks
```

## Deploy
```bash
cd /opt/unetlab/eve-web
./bin/create_v3_db.sh
```

Optional override:
```bash
V3_DB_NAME=eve-ng-db-v3 ./bin/create_v3_db.sh
```

## Inspect
```bash
PGPASSWORD='<db_password>' psql "host=127.0.0.1 port=5432 dbname=eve-ng-db-v3 user=eve-ng-ktk"
\dn
\dt auth.*
\dt labs.*
\dt runtime.*
\dt checks.*
\dv ops.*
```

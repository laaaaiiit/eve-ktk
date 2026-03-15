# EVE v2 Update Guide

This guide describes a safe update flow for the current v2 stack.

## Recommended (one command)

Run as `root`:

```bash
/opt/unetlab/eve-web/bin/update_system.sh
```

The script does:
1. Downloads the selected branch archive from GitHub.
2. Creates a code backup (`/opt/unetlab/data/backups/update/...`).
3. Unpacks and syncs code into `/opt/unetlab`.
4. Applies PostgreSQL migrations from `eve-web/migrations/*.sql`.
5. Runs quick syntax checks (`php -l`, `python3 -m compileall`).
6. Reloads/restarts active services (`apache2`, `eve-labtasks`).

## Script options

```bash
/opt/unetlab/eve-web/bin/update_system.sh \
  --repo https://github.com/laaaaiiit/eve-ktk.git \
  --branch main \
  --target-dir /opt/unetlab
```

Useful flags:
- `--dry-run` - show what would change, do not apply.
- `--skip-backup` - do not create backup archive.
- `--skip-migrations` - skip DB migrations.
- `--no-restart` - do not reload/restart services.

Optional env for private repos:
- `GITHUB_TOKEN=<token>`
- `CURL_CONNECT_TIMEOUT=15`
- `CURL_MAX_TIME=600`

## Manual update flow (if needed)

1. Backup current code:

```bash
mkdir -p /opt/unetlab/data/backups/update
tar -czf "/opt/unetlab/data/backups/update/unetlab-code-$(date +%Y%m%d-%H%M%S).tar.gz" \
  --exclude='.git' --exclude='data' --exclude='addons' --exclude='node_modules' \
  -C /opt/unetlab .
```

2. Update code (example with git):

```bash
cd /opt/unetlab
git fetch origin
git checkout main
git pull --ff-only origin main
```

3. Apply migrations:

```bash
cd /opt/unetlab/eve-web
set -a
source .env
set +a
./bin/apply_migrations.sh
```

4. Quick checks:

```bash
php -l /opt/unetlab/eve-web/public/index.php
python3 -m compileall /opt/unetlab/config_scripts
```

## Rollback

If update fails, restore from backup archive:

```bash
tar -xzf /opt/unetlab/data/backups/update/<backup-file>.tar.gz -C /opt/unetlab
```

Then re-run migrations only if needed.

## Notes

- DB credentials are read from `/opt/unetlab/eve-web/.env`.
- Migration state is tracked in `public.schema_migrations`.
- `addons/` and `data/` are intentionally not replaced by updater.

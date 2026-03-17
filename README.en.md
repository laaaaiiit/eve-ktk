# EVE-KTK (EVE v2)

Web platform for network labs based on EVE-NG with a new `v2` architecture:
- lab and node lifecycle management via Web UI;
- task queue and workers for power/check operations;
- lab checks (Linux/Windows/VPC/vIOS and others);
- built-in node consoles (telnet/VNC) and VM system console;
- PostgreSQL schema with migrations.

## Key features

- Full lab lifecycle: create, publish, collaborate, local copies.
- Hot connect/disconnect for supported interfaces.
- Scoring-based lab checks with run history and required items.
- Student task lists with copy sync support.
- User action audit and system logs.
- Task queue controls (limits, workers, status).

## Repository structure

- `eve-web/` - main v2 web code (API, UI, services, migrations, tools).
- `runtime/` - runtime templates and assets.
- `config_scripts/` - vendor bootstrap/config scripts.

## Target deployment (Linux)

Supported platform: Linux (recommended Ubuntu/Debian with KVM).

Deployment path:
- `/opt/unetlab`

Source repository:
- `https://github.com/laaaaiiit/eve-ktk.git`

Required components:
- Web: `nginx` + `php-fpm`
- PHP: `php-cli`, `php-fpm`, `php-pgsql`, `php-yaml`
- DB: PostgreSQL with credentials in `eve-web/.env`
- Background jobs: `eve-labtasks` (systemd)

### Debian auto-install

```bash
curl -fsSL https://raw.githubusercontent.com/laaaaiiit/eve-ktk/main/install-one-line.sh | bash
```

Alternative (manual installer run):

```bash
curl -fsSL https://raw.githubusercontent.com/laaaaiiit/eve-ktk/main/eve-web/bin/install_debian_eve_v2.sh -o /tmp/install_debian_eve_v2.sh
chmod +x /tmp/install_debian_eve_v2.sh
sudo /tmp/install_debian_eve_v2.sh
```

Details: `eve-web/docs/install-debian.md`.

## System update (recommended)

```bash
/opt/unetlab/eve-web/bin/update_system.sh
```

Useful flags:

```bash
/opt/unetlab/eve-web/bin/update_system.sh --dry-run
/opt/unetlab/eve-web/bin/update_system.sh --skip-backup
/opt/unetlab/eve-web/bin/update_system.sh --skip-migrations
/opt/unetlab/eve-web/bin/update_system.sh --no-restart
```

Details: `eve-web/docs/update-guide.md`.

## QEMU versions

QEMU binaries are resolved from:
- `/opt/qemu-<version>/bin/qemu-system-x86_64`
- fallback: `/opt/qemu/bin/qemu-system-x86_64`
- fallback: `/usr/bin/qemu-system-x86_64`

Installer prepares a compatible `/opt/qemu-*` layout automatically.

Check available versions:

```bash
ls -1 /opt | grep '^qemu'
```

Select node QEMU version:
1. By template default (`runtime/templates/.../*.yml`, `qemu_version`).
2. Per-node override in node settings (`qemu_version`).

Recommended template versions used in this project:
- `2.0.2`
- `2.4.0`
- `2.12.0`
- `3.1.0`
- `4.1.0`
- `5.2.0`
- `6.0.0`
- `7.2.9`
- `8.2.1`

Recommendation:
- keep older QEMU versions if any templates depend on them;
- validate image compatibility before switching template QEMU version.

# EVE v2 Debian Install

Полная автоматическая установка на чистый Debian с Nginx.

## Что делает скрипт

Скрипт `eve-web/bin/install_debian_eve_v2.sh`:
- ставит пакеты (`nginx`, `php-fpm`, `postgresql`, `websockify`, `qemu-utils`, и т.д.);
- клонирует/обновляет репозиторий в `/opt/unetlab`;
- создает БД и пользователя PostgreSQL;
- подготавливает `eve-web/.env`;
- применяет миграции `eve-web/migrations/*.sql`;
- создает sudoers-правила для runtime/system-console;
- настраивает nginx (включая `/vncws/` и `/collabws/` websocket proxy);
- устанавливает и включает `eve-labtasks.service`;
- применяет базовые sysctl параметры.

## Быстрый запуск

```bash
curl -fsSL https://raw.githubusercontent.com/laaaaiiit/eve-ktk/main/eve-web/bin/install_debian_eve_v2.sh -o /tmp/install_debian_eve_v2.sh
chmod +x /tmp/install_debian_eve_v2.sh
sudo /tmp/install_debian_eve_v2.sh
```

## Основные параметры

```bash
sudo /tmp/install_debian_eve_v2.sh \
  --repo https://github.com/laaaaiiit/eve-ktk.git \
  --branch main \
  --target-dir /opt/unetlab \
  --server-name _ \
  --db-host 127.0.0.1 \
  --db-port 5432 \
  --db-name eve-ng-db \
  --db-user eve-ng-ktk \
  --db-password '<password>'
```

Полный список: `sudo /tmp/install_debian_eve_v2.sh --help`.

## Проверка после установки

```bash
systemctl status nginx postgresql eve-labtasks --no-pager
curl -I http://127.0.0.1/login
tail -n 100 /opt/unetlab/data/Logs/task_worker.service.log
```

# EVE v2 Debian Install

Полная автоматическая установка на чистый Debian с Nginx.

## Что делает скрипт

Скрипт `eve-web/bin/install_debian_eve_v2.sh`:
- ставит пакеты (`nginx`, `php-fpm`, `postgresql`, `websockify`, `qemu-utils`, и т.д.);
- подготавливает layout версий QEMU в `/opt/qemu-*` (как на текущей VM):
  `1.3.1, 2.0.2, 2.2.0, 2.4.0, 2.5.0, 2.6.2, 2.12.0, 3.1.0, 4.1.0, 5.2.0, 6.0.0, 7.2.9, 8.2.1`;
- создает симлинк `/opt/qemu -> /opt/qemu-2.4.0` (по умолчанию);
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
  --db-password '<password>' \
  --install-eve-qemu
```

Полный список: `sudo /tmp/install_debian_eve_v2.sh --help`.

`--install-eve-qemu`:
- пытается установить пакет `eve-ng-qemu` через APT (если репозиторий настроен);
- если пакет недоступен, скрипт все равно создает совместимый `/opt/qemu-*` layout через системный `qemu-system-x86_64`.

## Проверка после установки

```bash
systemctl status nginx postgresql eve-labtasks --no-pager
curl -I http://127.0.0.1/login
tail -n 100 /opt/unetlab/data/Logs/task_worker.service.log
```

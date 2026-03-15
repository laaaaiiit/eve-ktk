# EVE-KTK (EVE v2)

Веб-платформа для сетевых лабораторий на базе EVE-NG с новой `v2` архитектурой:
- управление лабораториями и нодами через web UI;
- очередь задач и воркеры для операций питания/проверок;
- проверки лабораторий (Linux/Windows/VPC/vIOS и др.);
- встроенные консоли нод (telnet/VNC) и системная консоль VM;
- PostgreSQL-схема с миграциями.

## Основные возможности

- Полный цикл работы с лабораторией: создание, публикация, совместная работа, локальные копии.
- Горячее подключение/отключение интерфейсов для поддерживаемых шаблонов.
- Проверки лабораторий с баллами, история запусков, обязательные пункты.
- Задачи лаборатории для студентов и синхронизация копий.
- Аудит действий пользователей и системные логи.
- Управление очередью задач, лимитами и воркерами.

## Структура репозитория

- `eve-web/` — основной web-код v2 (API, UI, сервисы, миграции, утилиты).
- `runtime/` — runtime-шаблоны и ресурсы (иконки, templates).
- `config_scripts/` — скрипты первичной конфигурации вендорных образов.
- `wrappers/` — обертки/утилиты совместимости.
- `legacy/` — архив старой реализации (только для справки, не используется runtime v2).

## Быстрый старт (Linux)

Поддерживаемая платформа: Linux (рекомендуется Ubuntu/Debian с KVM).

Код должен находиться в каталоге:
- `/opt/unetlab`

Источник кода:
- GitHub репозиторий: `https://github.com/laaaaiiit/eve-ng-fork.git`

1. Установите базовые зависимости:

```bash
apt update
apt install -y git rsync curl tar php-cli python3 postgresql-client
```

2. Скачайте ПО и разместите в `/opt/unetlab`:

```bash
git clone https://github.com/laaaaiiit/eve-ng-fork.git /opt/unetlab
```

Если каталог уже существует и это git-копия:

```bash
cd /opt/unetlab
git fetch origin
git checkout main
git pull --ff-only origin main
```

3. Настройте переменные БД:

```bash
cp /opt/unetlab/eve-web/.env.example /opt/unetlab/eve-web/.env
```

4. Примените миграции:

```bash
cd /opt/unetlab/eve-web
./bin/apply_migrations.sh
```

5. Проверьте базово, что код читается:

```bash
php -l /opt/unetlab/eve-web/public/index.php
python3 -m compileall /opt/unetlab/config_scripts
```

## Обновление системы (рекомендуется)

Для обновления кода и миграций используйте скрипт:

```bash
/opt/unetlab/eve-web/bin/update_system.sh
```

Скрипт автоматически:
1. Скачивает выбранную ветку с GitHub.
2. Делает backup кода.
3. Обновляет файлы в `/opt/unetlab`.
4. Применяет миграции БД.
5. Выполняет базовые проверки.
6. Перезагружает/обновляет сервисы (если активны).

### Полезные параметры скрипта

```bash
/opt/unetlab/eve-web/bin/update_system.sh --dry-run
/opt/unetlab/eve-web/bin/update_system.sh --skip-backup
/opt/unetlab/eve-web/bin/update_system.sh --skip-migrations
/opt/unetlab/eve-web/bin/update_system.sh --no-restart
```

Подробная инструкция: `eve-web/docs/update-guide.md`.

## Важно

- Каталоги `addons/` и `data/` в репозитории не хранятся (образы/рабочие данные).
- Большие бинарные файлы и runtime-данные должны оставаться вне Git.
- `legacy/` предназначен только для анализа старого кода.

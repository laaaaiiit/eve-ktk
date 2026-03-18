# EVE-KTK (EVE v2)

[Русский](#ru) | [English](#en)

<a id="ru"></a>

EVE-KTK (EVE v2) - это web-платформа для сетевых лабораторий на базе EVE-NG с архитектурой, разделенной на основные сервисные уровни:
- `Nginx` выступает точкой входа: обслуживает HTTP(S), проксирует WebSocket-соединения (`/vncws/`, `/collabws/`) и маршрутизирует запросы к приложению.
- Прикладной слой реализован на `PHP-FPM` (API/UI v2), где обрабатываются операции с лабораториями, нодами, проверками и правами доступа.
- `PostgreSQL` используется как основное хранилище состояния системы (лабы, топология, проверки, задачи, аудит) с управлением схемой через миграции.
- Фоновые операции выполняются сервисом `eve-labtasks`, который обрабатывает очередь задач и изолирует долгие операции от web-запросов.

## Основные возможности

- Полный цикл работы с лабораторией: создание, публикация, совместная работа, локальные копии.
- Гибкая коммутация через `Cloud`-сети с разделением трафика по `VLAN` (включая trunk/access сценарии).
- Встроенная интеграция с `Wireshark` для захвата и анализа трафика на интерфейсах нод.
- Проверки и оценивание лабораторий: баллы, обязательные пункты, история запусков и прозрачные критерии проверки.
- Задачи лаборатории для студентов и синхронизация копий.
- Аудит действий пользователей и системные логи.
- Управление очередью задач, лимитами и воркерами.

## Структура репозитория

- `eve-web/` — основной web-код v2 (API, UI, сервисы, миграции, утилиты).
- `runtime/` — runtime-шаблоны и ресурсы (иконки, templates), а также bundled `vpcs` бинарник (`runtime/bin/vpcs/linux-amd64/vpcs`) для автономной установки.
- `config_scripts/` — скрипты первичной конфигурации вендорных образов.
- `addons/` — каталог пользовательских образов и файлов рантайма (в первую очередь `addons/qemu/*` для QEMU-шаблонов).

## Возможная реализация (Linux)

Поддерживаемая платформа: Linux (рекомендуется Ubuntu/Debian с KVM).

Код должен находиться в каталоге:
- `/opt/unetlab`

Источник кода:
- GitHub репозиторий: `https://github.com/laaaaiiit/eve-ktk.git`

Что нужно, чтобы сайт заработал:
- Web-сервер: `Nginx` + `php-fpm`.
- PHP: `php-cli`, `php-fpm`, `php-pgsql`, `php-yaml`.
- PostgreSQL: рабочая БД и учетные данные в `eve-web/.env`.
- Фоновые задачи: сервис `eve-labtasks` (systemd).

### Автоматическая установка Debian

```bash
curl -fsSL https://raw.githubusercontent.com/laaaaiiit/eve-ktk/main/install-one-line.sh | bash
```

Это единый скрипт для установки и обновления.
Повторный запуск той же команды на уже установленной системе выполнит обновление кода и миграции.

Скрипт автоматически:
1. Устанавливает зависимости (`nginx`, `php-fpm`, `postgresql`, `websockify`, и т.д.).
2. Клонирует/обновляет Git-репозиторий в `/opt/unetlab`.
3. Создает БД/пользователя PostgreSQL и подготавливает `.env`.
4. Применяет миграции.
5. Готовит набор версий QEMU в `/opt/qemu-*` (как на текущей VM) и симлинк `/opt/qemu`.
6. Настраивает `nginx` (HTTP + websocket proxy для `/vncws/` и `/collabws/`).
7. Создает/включает сервис `eve-labtasks`.
8. Применяет базовые `sysctl` настройки.
9. Проверяет `vpcs`: если системный бинарник старый/проблемный, использует bundled вариант из репозитория.

Подробности: `eve-web/docs/install-debian.md`.

## Версии QEMU

Бинарники QEMU используются из стандартных путей вида:
- `/opt/qemu-<version>/bin/qemu-system-x86_64`
- (fallback) `/opt/qemu/bin/qemu-system-x86_64`
- (fallback) `/usr/bin/qemu-system-x86_64`

При установке через `install_debian_eve_v2.sh` автоматически создается
совместимый `/opt/qemu-*` layout (как на текущей VM), а при флаге
`--install-eve-qemu` дополнительно выполняется попытка установить пакет `eve-ng-qemu`.

Как проверить, какие версии доступны на хосте:

```bash
ls -1 /opt | grep '^qemu'
```

Как выбрать версию для запуска ноды:
1. По умолчанию версия берется из шаблона (`runtime/templates/.../*.yml`, поле `qemu_version`).
2. Для конкретной ноды можно переопределить версию в настройках ноды (`qemu_version`).

Примеры версий, которые используются шаблонами в проекте:
- `2.0.2`
- `2.4.0`
- `2.12.0`
- `3.1.0`
- `4.1.0`
- `5.2.0`
- `6.0.0`
- `7.2.9`
- `8.2.1`

Рекомендация:
- не удаляйте старые версии QEMU, если у вас есть шаблоны, которые на них завязаны;
- при добавлении новых шаблонов сначала проверяйте совместимость образа с выбранной версией QEMU.

<a id="en"></a>

## English

EVE-KTK (EVE v2) is a web platform for network labs on top of EVE-NG, built with a service-oriented architecture:
- `Nginx` is the entry point: it serves HTTP(S), proxies WebSocket connections (`/vncws/`, `/collabws/`), and routes requests to the application layer.
- The application layer is implemented with `PHP-FPM` (v2 API/UI) and handles lab, node, validation, and access-control operations.
- `PostgreSQL` is the primary system of record for labs, topology, checks, tasks, and audit data, with schema management through migrations.
- Background processing is handled by the `eve-labtasks` service, which executes queued jobs and keeps long-running operations out of request-response paths.

## Key Features

- Full lab lifecycle: creation, publication, collaboration, and local copies.
- Flexible `Cloud` networking with traffic segmentation by `VLAN` (including trunk/access scenarios).
- Built-in `Wireshark` integration for packet capture and traffic analysis on node interfaces.
- Lab checks and grading: scoring, required items, run history, and transparent evaluation criteria.
- Student lab tasks and copy synchronization.
- User action audit trail and system logs.
- Task queue management, limits, and workers.

## Repository Structure

- `eve-web/` - main v2 web codebase (API, UI, services, migrations, utilities).
- `runtime/` - runtime templates and assets (icons, templates), plus bundled `vpcs` binary (`runtime/bin/vpcs/linux-amd64/vpcs`) for standalone installations.
- `config_scripts/` - initial vendor image configuration scripts.
- `addons/` - custom images and runtime files (primarily `addons/qemu/*` for QEMU templates).

## Suggested Linux Deployment

Supported platform: Linux (Ubuntu/Debian with KVM is recommended).

Code location:
- `/opt/unetlab`

Source code:
- GitHub repository: `https://github.com/laaaaiiit/eve-ktk.git`

Runtime requirements:
- Web server: `Nginx` + `php-fpm`.
- PHP: `php-cli`, `php-fpm`, `php-pgsql`, `php-yaml`.
- PostgreSQL: a working database and credentials in `eve-web/.env`.
- Background tasks: `eve-labtasks` service (systemd).

### Debian Automated Installation

```bash
curl -fsSL https://raw.githubusercontent.com/laaaaiiit/eve-ktk/main/install-one-line.sh | bash
```

This is a single script for both installation and upgrade.
Running the same command again on an existing system performs code update and migrations.

The script automatically:
1. Installs dependencies (`nginx`, `php-fpm`, `postgresql`, `websockify`, etc.).
2. Clones/updates the Git repository in `/opt/unetlab`.
3. Creates PostgreSQL DB/user and prepares `.env`.
4. Applies migrations.
5. Prepares the QEMU version set in `/opt/qemu-*` (matching the current VM) and symlink `/opt/qemu`.
6. Configures `nginx` (HTTP + websocket proxy for `/vncws/` and `/collabws/`).
7. Creates/enables the `eve-labtasks` service.
8. Applies baseline `sysctl` settings.
9. Verifies `vpcs`: if the system binary is outdated/problematic, uses the bundled binary from the repository.

Details: `eve-web/docs/install-debian.md`.

## QEMU Versions

QEMU binaries are resolved from standard paths:
- `/opt/qemu-<version>/bin/qemu-system-x86_64`
- (fallback) `/opt/qemu/bin/qemu-system-x86_64`
- (fallback) `/usr/bin/qemu-system-x86_64`

When installed via `install_debian_eve_v2.sh`, a compatible `/opt/qemu-*`
layout is created automatically (same as on the current VM). With
`--install-eve-qemu`, it also attempts to install the `eve-ng-qemu` package.

How to list versions available on the host:

```bash
ls -1 /opt | grep '^qemu'
```

How to select the version for node startup:
1. By default, the version comes from the template (`runtime/templates/.../*.yml`, `qemu_version` field).
2. You can override it per node in node settings (`qemu_version`).

Template versions currently used in the project:
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
- do not remove older QEMU versions if you have templates that depend on them;
- when adding new templates, validate image compatibility with the selected QEMU version first.

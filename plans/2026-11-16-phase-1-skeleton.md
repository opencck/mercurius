# План: фаза 1 — каркас

Временный план реализации фазы 1 из `ROADMAP.md`. Удаляется, когда фаза закрыта и итог перенесён в `BRIEF.md`.
Устройство — [BRIEF.md → Устройство приложения на opencck](../BRIEF.md#устройство-приложения-на-opencck).

## Решения на старте

- **Корневой namespace приложения — `OpenCCK\`** (рекомендация opencck для проекта); пакеты получат свои namespace.
- **Драйвер PostgreSQL — `amphp/postgres`**, а не `drift/dbal`: родной для AMPHP v3, без адаптера ReactPHP. Требует `ext-pgsql` (есть в образе); локально на Windows расширения нет, поэтому тесты с базой — в группе `integration` и запускаются на сервере.
- **Образ PostgreSQL — `pgvector/pgvector:pg17`**: `pgvector` понадобится памяти (HRR), ставить его позже значит менять образ с данными.
- **Миграции — свои**: SQL-файлы `migrations/NNNN_name.sql`, таблица `schema_migrations`, применение при старте в транзакции под advisory-блокировкой.
- **Логи — JSON в stdout** (Monolog через `amphp/log`), у каждого HTTP-запроса — `request_id` (берётся из `X-Request-Id` или генерируется, возвращается в ответе).

## Шаги

1. `composer.json` с зависимостями и скриптами `test`, `phpstan`, `psalm`, `check`; конфиги PHPUnit, PHPStan (`max`), Psalm (`errorLevel=1`).
2. Тесты вперёд (TDD) для чистой логики: конфигурация из окружения, порядок и разбор миграций, request id, health.
3. `src/`: `Config`, `App` (модули), `Server` (HTTP), маршруты `/health`, middleware request id и журнала запросов, логгер, `Database` (пул `amphp/postgres`), `Migrator`.
4. `index.php`: загрузка `.env`, логгер, база, миграции, HTTP-сервер, остановка по сигналу.
5. `Dockerfile`, `docker-compose.yml` (приложение в сетях `internal` и `web` с алиасом `mercurius`, PostgreSQL только в `internal`, порты наружу не публикуются).
6. `.github/workflows/ci.yml` по правилам «Качество кода и CI».
7. Развёртывание на сервер: `git clone` в `/home/rekryt/docker/mercurius/`, `.env` отдельно, `docker compose up -d --build`; порядок записать в `CLAUDE.md`.
8. Проверка: `composer check` локально и в Actions; на сервере контейнеры здоровы, миграции применены, `/health` отвечает внутри сети и снаружи после настройки NPM владельцем.

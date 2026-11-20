#!/bin/sh
# Обновление экземпляра Меркурия одной командой: копия → git pull → сабмодули модулей → сборка и запуск → уборка
# старых образов проекта. Неудачная копия останавливает обновление: без копии нечем откатиться.
#
#   bin/update.sh   — из каталога экземпляра на хосте (клон opencck/mercurius, ветка master)
#
# Откат: git checkout <прошлый коммит> && docker compose up -d --build; база — bin/restore.sh backups/<время>.
#
# spec: BRIEF.md#поставка-и-обслуживание
set -eu

cd "$(dirname "$0")/.."
[ -f .env ] || { echo "update: нет .env — это каталог экземпляра?" >&2; exit 1; }

# Сабмодули, нужные для работы; референсы (references/) на сервере не нужны.
MODULES="packages/llm packages/connectors packages/messenger packages/search packages/i18n packages/mcp-client packages/memory mcp/mail services/browser \
services/telegram-bot-api services/media-video services/media-docs skills/skill-creator"
PROJECT="$(grep -E '^COMPOSE_PROJECT_NAME=' .env | cut -d= -f2-)"
PROJECT="${PROJECT:-mercurius}"

before="$(git rev-parse --short HEAD)"
bin/backup.sh || { echo "update: копия не снята — обновление остановлено" >&2; exit 1; }
git pull --ff-only
git submodule sync -q
# shellcheck disable=SC2086
git submodule update --init --depth 1 $MODULES
after="$(git rev-parse --short HEAD)"
echo "update: $before → $after"

# --wait: до здоровья сервисов с проверкой здоровья; ошибка запуска — ненулевой выход
docker compose up -d --build --remove-orphans --wait
docker compose ps
docker compose logs --tail=200 app 2>/dev/null | grep 'Migrations checked' | tail -1 || true
docker image prune -f --filter "label=com.docker.compose.project=$PROJECT" >/dev/null
echo "update: готово ($after)"

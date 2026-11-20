#!/bin/sh
# Восстановление экземпляра Меркурия из копии bin/backup.sh: база целиком заменяется дампом, данные приложения и
# рабочие каталоги — архивом. .env не трогается (он может быть новее); копия .env лежит рядом — сверить вручную.
#
#   bin/restore.sh backups/<время>   — из каталога экземпляра на хосте; приложение останавливается на время
#
# spec: brief/operations.md#решено-в-фазе-12
set -eu

cd "$(dirname "$0")/.."
DIR="${1:?каталог копии: backups/<время>}"
[ -f "$DIR/db.dump" ] && [ -f "$DIR/app.tar.gz" ] || { echo "restore: в $DIR нет db.dump или app.tar.gz" >&2; exit 1; }

DB="$(grep -E '^POSTGRES_DB=' .env | cut -d= -f2-)"
USER="$(grep -E '^POSTGRES_USER=' .env | cut -d= -f2-)"

docker compose stop app
# Схема пересоздаётся: в базе не остаётся таблиц, которых не было в копии.
docker compose exec -T postgres psql -U "$USER" -d "$DB" -q -c 'DROP SCHEMA public CASCADE; CREATE SCHEMA public;'
docker compose exec -T postgres pg_restore -U "$USER" -d "$DB" --no-owner --exit-on-error < "$DIR/db.dump"
# Поверх данных приложения, с владельцами файлов из копии: от root через образ приложения.
docker compose run --rm --no-deps -T --user 0 --entrypoint tar -v "$PWD/storage/app:/a" app \
    -xzf - --strip-components=1 -C /a < "$DIR/app.tar.gz"
if [ -s "$DIR/workspace.tar.gz" ]; then
    docker compose run --rm --no-deps -T --user 0 --entrypoint tar -v "$PWD/storage/workspace:/w" app -xzf - -C /w < "$DIR/workspace.tar.gz"
fi
docker compose start app

echo "restore: восстановлено из $DIR (коммит копии: $(cat "$DIR/commit" 2>/dev/null || echo '?'))"

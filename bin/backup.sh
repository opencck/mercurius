#!/bin/sh
# Резервная копия экземпляра Меркурия: дамп базы, данные приложения и рабочие каталоги, .env.
# Профили браузера (storage/browser) не копируются: там входы в сайты, их проще войти заново, чем хранить в копиях.
# Каталог базы (storage/postgres) не копируется: вместо него — согласованный дамп pg_dump.
#
#   bin/backup.sh [каталог копий, по умолчанию backups/]   — запускать из каталога экземпляра на хосте
#
# Хранятся последние BACKUP_KEEP копий (по умолчанию 7). Каталог копий — только для владельца (700).
#
# spec: BRIEF.md#решено-в-фазе-12 — копия перед обновлением, восстановление проверено
# ref: references/hermes-agent/hermes_cli/backup.py@e96221fa:L100-L110 — профили браузера не копируются;
#      L475 (_zip_sqlite_snapshot) — согласованный снимок базы, а не её файлы
set -eu

cd "$(dirname "$0")/.."
DEST="${1:-backups}"
KEEP="${BACKUP_KEEP:-7}"
STAMP="$(date -u +%Y%m%d-%H%M%S)"
DIR="$DEST/$STAMP"

umask 077
mkdir -p "$DIR"
chmod 700 "$DEST"

# Имя и пользователь базы — из .env, без вывода значений.
DB="$(grep -E '^POSTGRES_DB=' .env | cut -d= -f2-)"
USER="$(grep -E '^POSTGRES_USER=' .env | cut -d= -f2-)"

docker compose exec -T postgres pg_dump -U "$USER" -d "$DB" -Fc > "$DIR/db.dump"
tar -czf "$DIR/app.tar.gz" -C storage app
# Рабочие каталоги участников принадлежат пользователям песочницы: читаются из образа приложения от root, только на чтение.
docker compose run --rm --no-deps -T --user 0 --entrypoint tar -v "$PWD/storage/workspace:/w:ro" app -czf - -C /w . > "$DIR/workspace.tar.gz"
cp .env "$DIR/env"
git rev-parse HEAD > "$DIR/commit" 2>/dev/null || true

# Проверка: дамп читается и в нём есть таблица миграций.
docker compose exec -T postgres pg_restore -l < "$DIR/db.dump" | grep -q 'TABLE public schema_migrations' \
    || { echo "backup: дамп не читается" >&2; exit 1; }

# Старые копии — прочь, кроме последних KEEP.
ls -1d "$DEST"/*/ 2>/dev/null | sort | head -n "-$KEEP" | while read -r old; do rm -rf "$old"; done

echo "backup: $DIR ($(du -sh "$DIR" | cut -f1))"

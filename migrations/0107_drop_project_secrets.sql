-- Таблица ссылок на личный секрет из проекта больше не нужна: её записи миграция 105 перенесла в секреты проекта
-- (secrets с project_id и сайтом), код таблицу не читает.
-- spec: brief/participants.md#секреты-проекта

DROP TABLE IF EXISTS project_secrets;

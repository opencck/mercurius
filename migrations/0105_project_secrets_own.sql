-- Секреты проекта со своими именами: запись в secrets с project_id и сайтом, для которого секрет годится. Имя
-- уникально в своём месте: личное — у участника, проекта — в проекте. Прежние ссылки на личный секрет
-- (project_secrets) становятся секретами проекта с тем же значением; таблица ссылок пока остаётся, код её не читает.
-- spec: brief/participants.md#секреты-проекта

ALTER TABLE secrets ADD COLUMN IF NOT EXISTS project_id bigint REFERENCES projects (id) ON DELETE CASCADE;
ALTER TABLE secrets ADD COLUMN IF NOT EXISTS site text;
DROP INDEX IF EXISTS secrets_name;
CREATE UNIQUE INDEX IF NOT EXISTS secrets_name_place ON secrets (lower(name), COALESCE(project_id, 0));

INSERT INTO secrets (name, kind, nonce, ciphertext, principal_id, project_id, site)
SELECT s.name, s.kind, s.nonce, s.ciphertext, s.principal_id, b.project_id, b.host
FROM project_secrets b JOIN secrets s ON s.id = b.secret_id
ON CONFLICT DO NOTHING;

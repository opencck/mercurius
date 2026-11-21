-- Подпись разрешения: что оно разрешает, словами (секреты замаскированы), для списка /permissions.
-- spec: brief/agent-loop.md#список-разрешений

ALTER TABLE approval_grants ADD COLUMN label text;

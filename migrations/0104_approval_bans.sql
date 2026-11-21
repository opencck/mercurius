-- Запреты: то, что не выполняется никогда, даже при разрешении. Область: общий (participant и area пусты),
-- участника (principal_id), проекта или задания (area 'project:N', 'job:N'). Ключи — как у разрешений, '*' — шаблон.
-- spec: brief/agent-loop.md#запреты

CREATE TABLE IF NOT EXISTS approval_bans (
    id bigserial PRIMARY KEY,
    principal_id bigint REFERENCES principals (id) ON DELETE CASCADE,
    area text NOT NULL DEFAULT '',
    key text NOT NULL,
    label text,
    created_by bigint REFERENCES principals (id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    removed_at timestamptz
);

CREATE UNIQUE INDEX IF NOT EXISTS approval_bans_once ON approval_bans (COALESCE(principal_id, 0), area, key) WHERE removed_at IS NULL;

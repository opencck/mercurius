-- Права проекта: инструменты и лимиты, которые задал его владелец. Нет строки — проект работает с правами своего
-- владельца как есть; есть — с этими правами, но не выше прав владельца проекта.
-- spec: brief/participants.md#права-проекта

CREATE TABLE IF NOT EXISTS project_policies (
    project_id bigint PRIMARY KEY REFERENCES projects (id) ON DELETE CASCADE,
    policy jsonb NOT NULL,
    updated_at timestamptz NOT NULL DEFAULT now()
);

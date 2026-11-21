-- Часовой пояс и тихие часы проекта. Нет строки или null — значения из окружения; тихие часы '' — нет.
-- spec: brief/scheduler.md#часовой-пояс-проекта

CREATE TABLE IF NOT EXISTS project_prefs (
    project_id bigint PRIMARY KEY REFERENCES projects (id) ON DELETE CASCADE,
    timezone text,
    quiet text,
    updated_at timestamptz NOT NULL DEFAULT now()
);

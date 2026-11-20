-- Сроки задач проектов, задания по событию (webhook) и ежедневная сводка владельцу.
-- spec: brief/scheduler.md#планировщик-и-проекты-1

ALTER TABLE project_tasks ADD COLUMN due_at timestamptz;

CREATE INDEX project_tasks_due ON project_tasks (due_at) WHERE status IN ('open', 'doing') AND due_at IS NOT NULL;

-- Задание по событию: имя секрета в хранилище, которым подписаны запросы. Пусто — webhook не принимается.
ALTER TABLE jobs ADD COLUMN hook_secret text;

-- Принятые доставки webhook: повтор той же доставки не запускает задание второй раз (и после перезапуска).
CREATE TABLE hook_deliveries (
    job_id bigint NOT NULL REFERENCES jobs (id) ON DELETE CASCADE,
    delivery_id text NOT NULL,
    received_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (job_id, delivery_id)
);

CREATE INDEX hook_deliveries_recent ON hook_deliveries (job_id, received_at);

-- Отправленные сводки: одна в день, захват до отправки.
CREATE TABLE digests (
    day date PRIMARY KEY,
    sent_at timestamptz NOT NULL DEFAULT now()
);

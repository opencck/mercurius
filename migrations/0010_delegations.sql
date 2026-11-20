-- Фоновые делегирования: переживают перезапуск, результат доставляется в беседу оркестратора.
-- spec: brief/orchestration.md#часть-3--фоновое-делегирование

CREATE TABLE delegations (
    id bigserial PRIMARY KEY,
    conversation_id bigint NOT NULL REFERENCES conversations (id) ON DELETE CASCADE,
    -- запуск-оркестратор: из его бюджета зарезервированы токены воркеров
    run_id bigint NOT NULL REFERENCES runs (id) ON DELETE CASCADE,
    principal_id bigint NOT NULL REFERENCES principals (id) ON DELETE CASCADE,
    call_id text NOT NULL,
    reserved integer NOT NULL CHECK (reserved >= 0),
    status text NOT NULL DEFAULT 'running' CHECK (status IN ('running', 'done', 'stopped')),
    -- сводный отчёт воркеров, когда делегирование закончено
    report text,
    created_at timestamptz NOT NULL DEFAULT now(),
    finished_at timestamptz,
    delivered_at timestamptz
);

CREATE INDEX delegations_running ON delegations (conversation_id) WHERE status = 'running';
CREATE INDEX delegations_undelivered ON delegations (id) WHERE status = 'done' AND delivered_at IS NULL;

CREATE TABLE delegation_tasks (
    delegation_id bigint NOT NULL REFERENCES delegations (id) ON DELETE CASCADE,
    position smallint NOT NULL,
    -- задание воркеру: цель, контекст, ответ, инструменты, роль, профиль
    task jsonb NOT NULL,
    -- доля резерва на задачу
    share integer NOT NULL CHECK (share >= 0),
    -- первая попытка и повтор каскада
    run_id bigint REFERENCES runs (id) ON DELETE SET NULL,
    retry_run_id bigint REFERENCES runs (id) ON DELETE SET NULL,
    -- отчёт по задаче, когда она закончена
    report jsonb,
    PRIMARY KEY (delegation_id, position)
);

CREATE INDEX delegation_tasks_run ON delegation_tasks (run_id) WHERE run_id IS NOT NULL;
CREATE INDEX delegation_tasks_retry_run ON delegation_tasks (retry_run_id) WHERE retry_run_id IS NOT NULL;

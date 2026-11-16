-- Запуски агента, вызовы инструментов, дописки во время запуска, политики участников.
-- spec: BRIEF.md#агентский-цикл-и-харнесс — запуск, сохранение до исполнения, один активный запуск на диалог
-- spec: BRIEF.md#права-участника — поправки политики участника

-- Системный промпт и модель фиксируются при открытии диалога и не меняются до его конца.
ALTER TABLE conversations
    ADD COLUMN system_prompt text,
    ADD COLUMN model text;

CREATE TABLE runs (
    id bigserial PRIMARY KEY,
    conversation_id bigint NOT NULL REFERENCES conversations (id) ON DELETE CASCADE,
    principal_id bigint NOT NULL REFERENCES principals (id) ON DELETE CASCADE,
    parent_run_id bigint REFERENCES runs (id) ON DELETE CASCADE,
    model text NOT NULL,
    status text NOT NULL CHECK (status IN ('running', 'waiting_approval', 'done', 'failed', 'stopped')),
    stop_reason text,
    max_turns integer NOT NULL,
    token_budget integer NOT NULL,
    run_timeout integer NOT NULL,
    tool_timeout integer NOT NULL,
    turns integer NOT NULL DEFAULT 0,
    tokens_used integer NOT NULL DEFAULT 0,
    tokens_reserved integer NOT NULL DEFAULT 0,
    active_ms bigint NOT NULL DEFAULT 0,
    -- usage.promptTokens() последнего вызова модели: якорь для оценки следующего входа
    prompt_anchor integer NOT NULL DEFAULT 0,
    -- предупреждение «израсходовано 80%» уже дано
    warned boolean NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CHECK (tokens_used >= 0 AND tokens_reserved >= 0)
);

-- Не больше одного активного запуска на диалог.
CREATE UNIQUE INDEX runs_one_active_per_conversation ON runs (conversation_id)
    WHERE status IN ('running', 'waiting_approval');
CREATE INDEX runs_by_status ON runs (status) WHERE status IN ('running', 'waiting_approval');

ALTER TABLE messages
    DROP CONSTRAINT messages_role_check,
    ADD CONSTRAINT messages_role_check CHECK (role IN ('user', 'assistant', 'tool')),
    ADD COLUMN run_id bigint REFERENCES runs (id) ON DELETE SET NULL,
    ADD COLUMN tool_calls jsonb,
    ADD COLUMN tool_call_id text;

CREATE INDEX messages_by_run ON messages (run_id, id) WHERE run_id IS NOT NULL;

CREATE TABLE tool_calls (
    run_id bigint NOT NULL REFERENCES runs (id) ON DELETE CASCADE,
    call_id text NOT NULL,
    position integer NOT NULL,
    name text NOT NULL,
    -- null, когда JSON аргументов не разобрался
    arguments jsonb,
    danger text NOT NULL CHECK (danger IN ('read', 'write', 'irreversible')),
    status text NOT NULL CHECK (status IN ('pending', 'running', 'done', 'failed', 'awaiting_approval', 'denied', 'interrupted')),
    result_preview text,
    result_path text,
    started_at timestamptz,
    finished_at timestamptz,
    PRIMARY KEY (run_id, call_id)
);

CREATE TABLE run_inbox (
    id bigserial PRIMARY KEY,
    run_id bigint NOT NULL REFERENCES runs (id) ON DELETE CASCADE,
    content text NOT NULL,
    external_message_id text,
    created_at timestamptz NOT NULL DEFAULT now(),
    consumed_at timestamptz
);

CREATE INDEX run_inbox_pending ON run_inbox (run_id, id) WHERE consumed_at IS NULL;

CREATE TABLE principal_policies (
    principal_id bigint PRIMARY KEY REFERENCES principals (id) ON DELETE CASCADE,
    -- только поправки к значениям роли
    policy jsonb NOT NULL,
    updated_at timestamptz NOT NULL DEFAULT now()
);

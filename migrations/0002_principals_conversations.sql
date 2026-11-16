-- Участники (люди и другие агенты), их учётные записи в каналах, диалоги, сообщения и вызовы моделей.
-- spec: BRIEF.md#участники-люди-и-агенты — одни роли для людей и агентов; владелец — только человек.

CREATE TABLE principals (
    id bigserial PRIMARY KEY,
    kind text NOT NULL CHECK (kind IN ('human', 'agent')),
    role text NOT NULL CHECK (role IN ('owner', 'admin', 'user')),
    display_name text,
    created_at timestamptz NOT NULL DEFAULT now(),
    CHECK (role <> 'owner' OR kind = 'human')
);

-- Ровно один владелец.
CREATE UNIQUE INDEX principals_single_owner ON principals ((role)) WHERE role = 'owner';

CREATE TABLE channel_identities (
    channel text NOT NULL,
    external_id text NOT NULL,
    principal_id bigint NOT NULL REFERENCES principals (id) ON DELETE CASCADE,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (channel, external_id)
);

CREATE TABLE conversations (
    id bigserial PRIMARY KEY,
    principal_id bigint NOT NULL REFERENCES principals (id) ON DELETE CASCADE,
    channel text NOT NULL,
    chat_id text NOT NULL,
    started_at timestamptz NOT NULL DEFAULT now(),
    closed_at timestamptz
);

-- Не больше одного открытого диалога на чат.
CREATE UNIQUE INDEX conversations_one_open_per_chat ON conversations (channel, chat_id) WHERE closed_at IS NULL;

CREATE TABLE messages (
    id bigserial PRIMARY KEY,
    conversation_id bigint NOT NULL REFERENCES conversations (id) ON DELETE CASCADE,
    role text NOT NULL CHECK (role IN ('user', 'assistant')),
    content text NOT NULL,
    external_message_id text,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX messages_by_conversation ON messages (conversation_id, id);

CREATE TABLE llm_calls (
    id bigserial PRIMARY KEY,
    conversation_id bigint REFERENCES conversations (id) ON DELETE SET NULL,
    purpose text NOT NULL,
    model text NOT NULL,
    finish_reason text,
    error_kind text,
    input_tokens integer NOT NULL DEFAULT 0,
    output_tokens integer NOT NULL DEFAULT 0,
    cache_read_tokens integer NOT NULL DEFAULT 0,
    cache_write_tokens integer NOT NULL DEFAULT 0,
    reasoning_tokens integer NOT NULL DEFAULT 0,
    cost_usd numeric(14, 8),
    duration_ms integer NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX llm_calls_by_time ON llm_calls (created_at);

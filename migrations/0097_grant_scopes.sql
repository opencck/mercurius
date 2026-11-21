-- Сроки и области постоянных разрешений: на беседу, после внешних данных, счётчик срабатываний; список
-- разрешений, которых не хватило запуску без владельца (заданию).
-- spec: brief/agent-loop.md#разрешения-навсегда

ALTER TABLE approval_grants ADD COLUMN conversation_id bigint REFERENCES conversations (id) ON DELETE CASCADE;
-- действует и в запуске, читавшем внешние данные
ALTER TABLE approval_grants ADD COLUMN outside boolean NOT NULL DEFAULT false;
ALTER TABLE approval_grants ADD COLUMN uses integer NOT NULL DEFAULT 0;
ALTER TABLE approval_grants ADD COLUMN last_used_at timestamptz;

DROP INDEX approval_grants_active;
CREATE UNIQUE INDEX approval_grants_active ON approval_grants (principal_id, key, COALESCE(conversation_id, 0)) WHERE revoked_at IS NULL;

-- более широкий ключ, который карточка предлагает разрешить навсегда
ALTER TABLE approvals ADD COLUMN wide_key text;

CREATE TABLE grant_requests (
    id bigserial PRIMARY KEY,
    -- область запуска без владельца: job:<номер>
    area text NOT NULL,
    principal_id bigint NOT NULL REFERENCES principals (id) ON DELETE CASCADE,
    -- [{"key": "...", "label": "..."}]
    items jsonb NOT NULL DEFAULT '[]',
    status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'granted', 'denied')),
    card_chat_id text,
    card_message_id text,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX grant_requests_pending ON grant_requests (area) WHERE status = 'pending';

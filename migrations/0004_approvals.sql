-- Подтверждения необратимых действий, сохранённые разрешения, посетители.
-- spec: brief/agent-loop.md#подтверждения-и-запреты — ожидание в БД, решение атомарно, «разрешать всегда»
-- spec: brief/decisions.md#участники-люди-и-агенты — новый посетитель ждёт решения владельца

ALTER TABLE principals
    DROP CONSTRAINT principals_role_check,
    ADD CONSTRAINT principals_role_check CHECK (role IN ('owner', 'admin', 'user', 'visitor')),
    ADD COLUMN status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'pending', 'rejected'));

CREATE TABLE approvals (
    id bigserial PRIMARY KEY,
    run_id bigint NOT NULL REFERENCES runs (id) ON DELETE CASCADE,
    call_id text NOT NULL,
    -- кто просил о действии (решает всегда владелец)
    principal_id bigint NOT NULL REFERENCES principals (id) ON DELETE CASCADE,
    tool text NOT NULL,
    -- показ действия целиком, секреты замаскированы
    summary text NOT NULL,
    grant_key text,
    status text NOT NULL CHECK (status IN ('pending', 'approved', 'denied', 'expired')),
    reason text,
    decided_by bigint REFERENCES principals (id) ON DELETE SET NULL,
    card_chat_id text,
    card_message_id text,
    created_at timestamptz NOT NULL DEFAULT now(),
    expires_at timestamptz NOT NULL,
    decided_at timestamptz,
    UNIQUE (run_id, call_id)
);

CREATE INDEX approvals_pending ON approvals (expires_at) WHERE status = 'pending';

CREATE TABLE approval_grants (
    id bigserial PRIMARY KEY,
    principal_id bigint NOT NULL REFERENCES principals (id) ON DELETE CASCADE,
    key text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    revoked_at timestamptz
);

CREATE UNIQUE INDEX approval_grants_active ON approval_grants (principal_id, key) WHERE revoked_at IS NULL;

-- Незнакомые агенты, которые доказали свой ключ на входе и ждут решения владельца: одна карточка на ключ.
-- spec: brief/network.md#незнакомый-агент-стучится

CREATE TABLE agent_requests (
    id bigserial PRIMARY KEY,
    fingerprint text NOT NULL UNIQUE,
    name text NOT NULL,
    url text NOT NULL DEFAULT '',
    transport text NOT NULL,
    status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'allowed', 'rejected')),
    created_at timestamptz NOT NULL DEFAULT now(),
    decided_at timestamptz
);

CREATE INDEX agent_requests_pending ON agent_requests (id) WHERE status = 'pending';

-- Keys of the WebSocket API: the owner issues one to a participant, it is shown once; only its SHA-256 is kept.
-- spec: BRIEF.md#второй-канал-websocket-api
CREATE TABLE api_keys (
    id bigserial PRIMARY KEY,
    principal_id bigint NOT NULL REFERENCES principals (id) ON DELETE CASCADE,
    name text NOT NULL DEFAULT '',
    hash text NOT NULL UNIQUE,
    created_at timestamptz NOT NULL DEFAULT now(),
    used_at timestamptz,
    revoked_at timestamptz
);

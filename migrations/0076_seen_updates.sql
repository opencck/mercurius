-- Updates of the messenger already taken: one redelivered after a restart (an unacknowledged webhook or getUpdates
-- batch) is dropped. Kept a day.
-- spec: brief/channels.md#канал-необязательные-возможности-и-разрезанные-тексты
CREATE TABLE seen_updates (
    key text PRIMARY KEY,
    seen_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX seen_updates_seen_at ON seen_updates (seen_at);

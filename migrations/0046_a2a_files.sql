-- Files between agents (A2A): sent ahead of a message by POST /a2a/files, or put into the answer of a task. A file is
-- given only to the agent it is meant for (owner_id). Unused ones go after an hour, used ones after a day.
-- spec: BRIEF.md#между-агентами

CREATE TABLE a2a_files (
    id text PRIMARY KEY,
    owner_id bigint NOT NULL REFERENCES principals (id) ON DELETE CASCADE,
    task_id text,
    name text NOT NULL,
    media_type text NOT NULL,
    size integer NOT NULL,
    bytes bytea NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    used_at timestamptz
);

CREATE INDEX a2a_files_task ON a2a_files (task_id) WHERE task_id IS NOT NULL;

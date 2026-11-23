-- The limit of a conversation in the context and the percent of it at which compaction starts, set for a project
-- or for a participant's own private chats; null — the agent's setting.
-- spec: brief/agent-loop.md#объём-беседы-и-порог-сжатия
CREATE TABLE context_limits (
    kind text NOT NULL CHECK (kind IN ('project', 'principal')),
    ref_id bigint NOT NULL,
    max_tokens integer CHECK (max_tokens IS NULL OR max_tokens > 0),
    compact_percent integer CHECK (compact_percent IS NULL OR compact_percent BETWEEN 10 AND 95),
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (kind, ref_id)
);

-- Bots and visitors a group's participants asked the agent not to hear in that group: a user id or a @name.
-- spec: BRIEF.md#группа
CREATE TABLE group_ignores (
    channel text NOT NULL,
    chat_id text NOT NULL,
    who text NOT NULL,
    added_by bigint,
    added_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (channel, chat_id, who)
);

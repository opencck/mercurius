-- The forum topic a job was set in: its runs and reports go back into that topic, not to the general chat.
-- spec: BRIEF.md#telegram-группы-каналы-и-темы — a topic is a place of its own
ALTER TABLE jobs ADD COLUMN thread_id text NOT NULL DEFAULT '';

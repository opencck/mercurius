-- Who wrote each line of a group's talk: a run in the group gets no more rights than the weakest one who spoke there
-- in the last day. Lines written before this migration have no author.
-- spec: brief/decisions.md#решения-по-доработкам-2026-11-18 — in a group the rights of the weakest active participant
ALTER TABLE group_talk ADD COLUMN user_id text NOT NULL DEFAULT '';
CREATE INDEX group_talk_speakers ON group_talk (channel, chat_id, thread_id, created_at) WHERE user_id <> '';

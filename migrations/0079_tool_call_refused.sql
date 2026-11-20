-- A call the participant's policy did not allow (or a tool that does not exist): the review of traces counts them.
-- spec: brief/self-improvement.md#самоулучшение
ALTER TABLE tool_calls ADD COLUMN refused boolean NOT NULL DEFAULT false;

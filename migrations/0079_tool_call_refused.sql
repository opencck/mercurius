-- A call the participant's policy did not allow (or a tool that does not exist): the review of traces counts them.
-- spec: brief/decisions.md#самоулучшение-решено-2026-11-18
ALTER TABLE tool_calls ADD COLUMN refused boolean NOT NULL DEFAULT false;

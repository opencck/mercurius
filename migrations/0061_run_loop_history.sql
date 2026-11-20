-- The signatures of the last tool calls of a run, as the loop guard saw them: a run resumed after an approval or a
-- restart goes on counting its repeats instead of starting over.
-- spec: brief/agent-loop.md#запасная-модель-и-ограничитель-зацикливания — the loop guard of a run outlives a restart
ALTER TABLE runs ADD COLUMN loop_history jsonb NOT NULL DEFAULT '[]';

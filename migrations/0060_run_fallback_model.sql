-- The model a run switched to after its own failed: a run resumed after an approval or a restart goes on with it and
-- does not switch a second time.
-- spec: brief/decisions.md#решения-по-доработкам-2026-11-18 — the fallback model is kept in the run
ALTER TABLE runs ADD COLUMN fallback_model text;

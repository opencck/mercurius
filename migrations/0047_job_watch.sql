-- Watching: a job looks at an address on its schedule and calls the model only when what it gives changed.
-- spec: BRIEF.md#планировщик-и-проекты

ALTER TABLE jobs ADD COLUMN watch_url text;
ALTER TABLE jobs ADD COLUMN watch_hash text;

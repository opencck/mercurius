-- Watching: a job looks at an address on its schedule and calls the model only when what it gives changed.
-- spec: brief/scheduler.md#планировщик-и-проекты-1

ALTER TABLE jobs ADD COLUMN watch_url text;
ALTER TABLE jobs ADD COLUMN watch_hash text;

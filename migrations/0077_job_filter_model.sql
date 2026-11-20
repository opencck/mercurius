-- Which webhook events wake a job (and how long a burst is merged), and the model role its runs use.
-- spec: brief/scheduler.md#как-сделано-задания-по-событию-и-проекты
ALTER TABLE jobs ADD COLUMN hook_filter text NOT NULL DEFAULT '';
ALTER TABLE jobs ADD COLUMN model_role text NOT NULL DEFAULT '';

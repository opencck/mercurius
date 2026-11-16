-- Отчёт /delegation и надёжность моделей читают запуски воркеров за период.
-- spec: BRIEF.md#часть-4--проверяющий-и-данные-трасс

CREATE INDEX runs_workers_by_time ON runs (created_at) WHERE parent_run_id IS NOT NULL;

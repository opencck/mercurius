-- Проверяющий воркер фоновой части: его запуск продолжается после перезапуска, как попытки воркера.
-- spec: brief/orchestration.md#часть-4--проверяющий-и-данные-трасс

ALTER TABLE delegation_tasks
    ADD COLUMN verify_run_id bigint REFERENCES runs (id) ON DELETE SET NULL;

CREATE INDEX delegation_tasks_verify_run ON delegation_tasks (verify_run_id) WHERE verify_run_id IS NOT NULL;

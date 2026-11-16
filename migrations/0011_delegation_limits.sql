-- Фоновое делегирование: срок всего делегирования, остановка, переживающая перезапуск, запуск, в который доставлен отчёт.
-- spec: BRIEF.md#часть-3--фоновое-делегирование

ALTER TABLE delegations
    -- после срока неначатые части не начинаются, начатые заканчиваются своим сроком
    ADD COLUMN deadline_at timestamptz NOT NULL DEFAULT now() + interval '30 minutes',
    -- /stop пришёл: после перезапуска делегирование закрывается, а не продолжается
    ADD COLUMN stop_requested boolean NOT NULL DEFAULT false,
    -- запуск, который начался с отчёта: из него новое фоновое делегирование не начинается
    ADD COLUMN delivered_run_id bigint REFERENCES runs (id) ON DELETE SET NULL;

CREATE INDEX delegations_running_by_principal ON delegations (principal_id) WHERE status = 'running';
CREATE INDEX delegations_delivered_run ON delegations (delivered_run_id) WHERE delivered_run_id IS NOT NULL;

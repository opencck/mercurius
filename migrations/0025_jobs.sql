-- Задания по расписанию: агент ставит себе напоминания и проверки, планировщик запускает и доставляет итог.
-- spec: BRIEF.md#планировщик-и-проекты

CREATE TABLE jobs (
    id bigserial PRIMARY KEY,
    principal_id bigint NOT NULL REFERENCES principals (id) ON DELETE CASCADE,
    -- куда доставить итог: чат, где задание поставлено
    channel text NOT NULL,
    chat_id text NOT NULL,
    title text NOT NULL,
    prompt text NOT NULL,
    -- once:<ISO-время> | every:<минут> | daily:<ЧЧ:ММ>
    schedule text NOT NULL,
    next_run_at timestamptz,
    last_run_at timestamptz,
    runs integer NOT NULL DEFAULT 0,
    last_result text,
    -- итог, ждущий конца тихих часов
    pending_report text,
    status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'paused', 'done')),
    -- запуск идёт: второй не начнётся; после перезапуска сбрасывается
    running boolean NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX jobs_due ON jobs (next_run_at) WHERE status = 'active';

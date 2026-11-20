-- Трассы запусков: шаги с токенами, временем и стоимостью; полные запросы и ответы со сроком хранения.
-- spec: brief/operations.md#шаги-и-хранение

CREATE TABLE trace_spans (
    id bigserial PRIMARY KEY,
    run_id bigint NOT NULL REFERENCES runs (id) ON DELETE CASCADE,
    parent_id bigint REFERENCES trace_spans (id) ON DELETE CASCADE,
    kind text NOT NULL CHECK (kind IN ('run', 'model', 'tool', 'approval', 'compaction', 'classification')),
    name text NOT NULL,
    started_at timestamptz NOT NULL,
    duration_ms integer,
    status text NOT NULL CHECK (status IN ('running', 'ok', 'error', 'cancelled')),
    input_tokens integer NOT NULL DEFAULT 0,
    output_tokens integer NOT NULL DEFAULT 0,
    cache_read_tokens integer NOT NULL DEFAULT 0,
    cache_write_tokens integer NOT NULL DEFAULT 0,
    reasoning_tokens integer NOT NULL DEFAULT 0,
    cost_usd numeric(14, 8),
    -- actual — от провайдера, estimated — по ценам каталога, unknown
    cost_status text NOT NULL DEFAULT 'unknown' CHECK (cost_status IN ('actual', 'estimated', 'unknown')),
    error text,
    attrs jsonb NOT NULL DEFAULT '{}'
);

CREATE INDEX trace_spans_by_run ON trace_spans (run_id, id);
CREATE INDEX trace_spans_by_time ON trace_spans (started_at);
-- один шаг запуска на запуск: продолженный после перезапуска запуск дописывает тот же
CREATE UNIQUE INDEX trace_spans_one_run ON trace_spans (run_id) WHERE kind = 'run';

-- Полные запросы и ответы модели, уже замаскированные; удаляются по сроку TRACE_RETENTION_DAYS.
CREATE TABLE trace_payloads (
    span_id bigint PRIMARY KEY REFERENCES trace_spans (id) ON DELETE CASCADE,
    request jsonb NOT NULL,
    response jsonb NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX trace_payloads_by_time ON trace_payloads (created_at);

ALTER TABLE llm_calls ADD COLUMN run_id bigint REFERENCES runs (id) ON DELETE SET NULL;
CREATE INDEX llm_calls_by_run ON llm_calls (run_id) WHERE run_id IS NOT NULL;

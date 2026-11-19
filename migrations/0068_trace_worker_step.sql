-- A worker run is a step of its orchestrator's trace: /trace shows the worker's steps under it.
-- spec: BRIEF.md#трассировка-и-метрики
ALTER TABLE trace_spans DROP CONSTRAINT trace_spans_kind_check;
ALTER TABLE trace_spans ADD CONSTRAINT trace_spans_kind_check
    CHECK (kind IN ('run', 'model', 'tool', 'approval', 'compaction', 'classification', 'worker'));

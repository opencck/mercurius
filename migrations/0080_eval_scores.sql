-- Scores of the evaluation set by category for a version of the notes to the system prompt: the gate records both the
-- current notes and the candidate, /prompt scores compares versions.
-- spec: brief/decisions.md#самоулучшение-решено-2026-11-18
CREATE TABLE eval_scores (
    id bigserial PRIMARY KEY,
    version text NOT NULL,
    model text NOT NULL,
    category text NOT NULL,
    mean double precision NOT NULL,
    se double precision NOT NULL,
    n integer NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX eval_scores_version ON eval_scores (version, created_at DESC);

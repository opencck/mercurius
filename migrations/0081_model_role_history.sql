-- Every change of the model of a role: which, by whom, why, when; /models rollback <number> brings a record back.
-- spec: BRIEF.md#самоулучшение-решено-2026-11-18
CREATE TABLE model_role_history (
    id bigserial PRIMARY KEY,
    role text NOT NULL,
    provider text NOT NULL,
    model text NOT NULL,
    reason text NOT NULL,
    set_by text NOT NULL,
    principal_id bigint,
    created_at timestamptz NOT NULL DEFAULT now()
);

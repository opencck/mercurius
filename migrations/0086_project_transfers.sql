-- An offer to hand a project over to another participant: it waits for the one it is offered to.
-- spec: brief/participants.md#доступ-к-проектам-и-передача — a project passes to another participant with their consent
CREATE TABLE project_transfers (
    id bigserial PRIMARY KEY,
    project_id bigint NOT NULL REFERENCES projects (id) ON DELETE CASCADE,
    -- NULL — the owner of the agent
    from_id bigint REFERENCES principals (id) ON DELETE CASCADE,
    to_id bigint REFERENCES principals (id) ON DELETE CASCADE,
    status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'accepted', 'declined', 'cancelled')),
    created_at timestamptz NOT NULL DEFAULT now(),
    decided_at timestamptz
);
CREATE UNIQUE INDEX project_transfers_pending ON project_transfers (project_id) WHERE status = 'pending';

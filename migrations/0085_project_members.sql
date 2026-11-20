-- Who else, besides its owner, works with a project and how far: read, write or full.
-- spec: brief/participants.md#доступ-к-проектам-и-передача — the owner of a project gives other participants access to it
CREATE TABLE project_members (
    project_id bigint NOT NULL REFERENCES projects (id) ON DELETE CASCADE,
    principal_id bigint NOT NULL REFERENCES principals (id) ON DELETE CASCADE,
    access text NOT NULL CHECK (access IN ('read', 'write', 'full')),
    granted_by bigint REFERENCES principals (id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (project_id, principal_id)
);
CREATE INDEX project_members_principal ON project_members (principal_id);

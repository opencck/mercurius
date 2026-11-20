-- Who listens to a project: an agent that subscribed gets what is said in its chats and topics over its connection.
-- spec: brief/network.md#проект-как-канал-для-агентов
CREATE TABLE project_subscriptions (
    project_id bigint NOT NULL REFERENCES projects (id) ON DELETE CASCADE,
    principal_id bigint NOT NULL REFERENCES principals (id) ON DELETE CASCADE,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (project_id, principal_id)
);
-- the agents that already worked with a project went on hearing it
INSERT INTO project_subscriptions (project_id, principal_id)
SELECT m.project_id, m.principal_id FROM project_members m JOIN principals p ON p.id = m.principal_id WHERE p.kind = 'agent'
ON CONFLICT DO NOTHING;

-- A secret its author gave to a project: those who work in the project use it in requests to one site, never see it.
-- spec: brief/participants.md#ресурсы-проекта
CREATE TABLE project_secrets (
    secret_id bigint PRIMARY KEY REFERENCES secrets (id) ON DELETE CASCADE,
    project_id bigint NOT NULL REFERENCES projects (id) ON DELETE CASCADE,
    host text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX project_secrets_project ON project_secrets (project_id);

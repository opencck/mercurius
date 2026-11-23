-- Sleep of a project: the projects whose facts the model of sleep checks and adds to at night. Off by default.
-- spec: brief/memory.md#сон-проекта
CREATE TABLE project_sleep (
    project_id bigint PRIMARY KEY REFERENCES projects (id) ON DELETE CASCADE,
    since timestamptz NOT NULL DEFAULT now()
);

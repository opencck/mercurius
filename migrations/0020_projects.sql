-- Проекты, задачи проекта, отношение участника к проекту; активный проект чата.
-- spec: brief/memory.md#проекты-задачи-и-отношение-участника-к-проекту

CREATE TABLE projects (
    id bigserial PRIMARY KEY,
    name text NOT NULL,
    goal text NOT NULL DEFAULT '',
    status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'paused', 'done')),
    -- карточка: состав, репозитории, серверы, стек, принятые решения; ведёт агент
    card text NOT NULL DEFAULT '',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX projects_name ON projects (lower(name));

CREATE TABLE project_tasks (
    id bigserial PRIMARY KEY,
    project_id bigint NOT NULL REFERENCES projects (id) ON DELETE CASCADE,
    parent_id bigint REFERENCES project_tasks (id) ON DELETE CASCADE,
    title text NOT NULL,
    status text NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'doing', 'done', 'dropped')),
    -- профиль задачи: оценки по осям
    profile jsonb,
    -- итог: чем закончилась (для закрытой задачи обязателен)
    result text,
    created_at timestamptz NOT NULL DEFAULT now(),
    closed_at timestamptz
);

CREATE INDEX project_tasks_open ON project_tasks (project_id, id) WHERE status IN ('open', 'doing');

-- Отношение участника к проекту: роль, приоритет, ожидания, предпочтения — декларативно, от первого лица участника.
CREATE TABLE project_relations (
    project_id bigint NOT NULL REFERENCES projects (id) ON DELETE CASCADE,
    principal_id bigint NOT NULL REFERENCES principals (id) ON DELETE CASCADE,
    text text NOT NULL,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (project_id, principal_id)
);

-- Активный проект чата: его карточка, отношение пишущего и открытые задачи идут в контекст новой беседы.
CREATE TABLE chat_projects (
    channel text NOT NULL,
    chat_id text NOT NULL,
    project_id bigint NOT NULL REFERENCES projects (id) ON DELETE CASCADE,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (channel, chat_id)
);

-- Скиллы агента: каталог с версиями; в контексте — только индекс, тело — по запросу.
-- spec: BRIEF.md#скиллы-и-mcp

CREATE TABLE skills (
    id bigserial PRIMARY KEY,
    name text NOT NULL,
    -- одна строка для индекса
    description text NOT NULL DEFAULT '',
    body text NOT NULL DEFAULT '',
    -- draft — ещё не одобрен владельцем; active — в индексе; disabled — выключен владельцем
    status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'active', 'disabled')),
    -- owner | agent | external: от кого пришёл первый текст
    origin text NOT NULL,
    version integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX skills_name ON skills (name);

-- Каждая версия хранится: предложенная, принятая или отклонённая; откат — к любой принятой.
CREATE TABLE skill_versions (
    id bigserial PRIMARY KEY,
    skill_id bigint NOT NULL REFERENCES skills (id) ON DELETE CASCADE,
    version integer NOT NULL,
    description text NOT NULL,
    body text NOT NULL,
    status text NOT NULL DEFAULT 'proposed' CHECK (status IN ('proposed', 'accepted', 'rejected')),
    reason text NOT NULL DEFAULT '',
    principal_id bigint REFERENCES principals (id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (skill_id, version)
);

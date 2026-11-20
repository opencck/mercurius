-- The journal of skill edits: what a skill's folder held before each write, so one edit can be rolled back.
-- spec: brief/skills-mcp.md#скиллы-и-mcp
CREATE TABLE skill_edits (
    id bigserial PRIMARY KEY,
    owner_id bigint NOT NULL,
    dir text NOT NULL,
    name text NOT NULL,
    -- the text files of the folder before the edit, path => text; null — the skill was not there
    before jsonb,
    source text NOT NULL DEFAULT '',
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX skill_edits_by_skill ON skill_edits (owner_id, dir, id DESC);

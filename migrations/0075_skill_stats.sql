-- The use of skills by place and name: the same name in two places (a person's skills, a project's) is two skills.
-- skill_usage stays for a rollback of the code; its rows move here with an empty place and are claimed by the first
-- write of their skill.
-- spec: brief/skills-mcp.md#скиллы-и-mcp
CREATE TABLE skill_stats (
    place text NOT NULL,
    name text NOT NULL,
    views integer NOT NULL DEFAULT 0,
    last_viewed timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    pinned boolean NOT NULL DEFAULT false,
    off boolean NOT NULL DEFAULT false,
    PRIMARY KEY (place, name)
);
INSERT INTO skill_stats (place, name, views, last_viewed, pinned, off)
SELECT '', name, views, last_viewed, pinned, off FROM skill_usage;

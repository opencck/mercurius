-- Which wider layers of secrets a context inherits, off until turned on: a project — the secrets of its owner (turned
-- on by the project owner) and of the agent (by an owner of the agent); a member's personal context — the secrets of
-- the agent (by an owner of the agent). A context without a row inherits nothing.
-- spec: brief/skills-mcp.md#подстановка-секретов
CREATE TABLE secret_inheritance (
    context text PRIMARY KEY CHECK (context ~ '^(member|project):[0-9]+$'),
    owner boolean NOT NULL DEFAULT false,
    agent boolean NOT NULL DEFAULT false,
    -- who opened their own secrets to the project: the consent holds only while they own it
    owner_by bigint REFERENCES principals (id) ON DELETE SET NULL
);

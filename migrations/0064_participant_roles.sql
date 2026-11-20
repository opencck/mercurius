-- The owner's own list of roles of participants: a name, a title, a place in the order, powers and a policy template.
-- admin, user and visitor are only the starting set; the owner is not a role of the list.
-- spec: brief/participants.md#свои-роли-решение-владельца-2026-11-19
CREATE TABLE participant_roles (
    name text PRIMARY KEY CHECK (name ~ '^[a-z0-9_-]{1,32}$' AND name <> 'owner'),
    title text NOT NULL DEFAULT '',
    position integer NOT NULL,
    powers text[] NOT NULL DEFAULT '{}',
    policy jsonb NOT NULL DEFAULT '{}'
);

INSERT INTO participant_roles (name, position, powers) VALUES
    ('admin', 10, '{tools,skills,projects,secrets,admit,participants,publish,reports}'),
    ('user', 20, '{tools,skills,projects,secrets}'),
    ('visitor', 30, '{}');

ALTER TABLE principals
    DROP CONSTRAINT principals_role_check,
    ADD CONSTRAINT principals_role_check CHECK (role ~ '^[a-z0-9_-]{1,32}$');

-- A mailbox a participant connected: where it is and which secret of the vault opens it. The password never lies here.
-- A mailbox may be given to a project: its chats and jobs then read and send through it.
-- spec: brief/skills-mcp.md#ящики-участников-и-проектов
CREATE TABLE mailboxes (
    id bigserial PRIMARY KEY,
    principal_id bigint NOT NULL REFERENCES principals (id) ON DELETE CASCADE,
    name text NOT NULL,
    host text NOT NULL,
    imap_port integer NOT NULL DEFAULT 993,
    smtp_port integer NOT NULL DEFAULT 465,
    username text NOT NULL,
    from_address text NOT NULL,
    authserv_id text NOT NULL DEFAULT '',
    secret_id bigint NOT NULL REFERENCES secrets (id) ON DELETE CASCADE,
    project_id bigint REFERENCES projects (id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX mailboxes_name ON mailboxes (principal_id, lower(name));
CREATE INDEX mailboxes_project ON mailboxes (project_id) WHERE project_id IS NOT NULL;

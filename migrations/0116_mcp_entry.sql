-- The approved entry of a connection is kept whole: the server is called only by it, with the secrets it names put in
-- at the call. The file in the sandbox is a request; this is what was approved.
-- Secrets of the agent are the widest layer a name is looked up in: project, owner of the context, agent. An agent
-- secret may share its name with a personal one. A binding of a secret to an MCP is not used: the approval of the
-- entry, whose card names its secrets and their layers, is the consent.
-- spec: brief/skills-mcp.md#подстановка-секретов
ALTER TABLE mcp_connections ADD COLUMN entry jsonb;

ALTER TABLE secrets ADD COLUMN agent boolean NOT NULL DEFAULT false;
DROP INDEX IF EXISTS secrets_name_place;
CREATE UNIQUE INDEX secrets_name_place ON secrets (lower(name), COALESCE(project_id, 0), agent);

ALTER TABLE secrets DROP CONSTRAINT IF EXISTS secrets_site_or_mcp;
ALTER TABLE secrets DROP COLUMN IF EXISTS mcp;

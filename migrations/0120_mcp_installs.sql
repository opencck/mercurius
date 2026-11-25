-- An installed MCP gets a number (its sandbox user is 800000 + the number), its installer, the revision before the
-- current one for a rollback, the entry the install approved and the newest revision the daily check saw.
-- spec: brief/skills-mcp.md#три-слоя-установленный-mcp-запись-подключение
ALTER TABLE mcp_installs ADD COLUMN id serial UNIQUE;
ALTER TABLE mcp_installs ADD COLUMN installed_by integer;
ALTER TABLE mcp_installs ADD COLUMN previous text;
ALTER TABLE mcp_installs ADD COLUMN entry jsonb;
ALTER TABLE mcp_installs ADD COLUMN latest text;
-- the steps of the approved plan: an update runs them again on the new revision
ALTER TABLE mcp_installs ADD COLUMN steps jsonb NOT NULL DEFAULT '[]';

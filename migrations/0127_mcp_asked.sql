-- The hash of the entry of .mcp.json whose card was sent: a restart of the application does not send it again.
-- spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи
ALTER TABLE mcp_connections ADD COLUMN asked_hash text;

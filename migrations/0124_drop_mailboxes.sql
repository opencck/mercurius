-- Mailboxes are entries of .mcp.json of our mail MCP now; the former table held none on any instance. Their secrets
-- stay in the vault.
-- spec: brief/skills-mcp.md#ящики-участников-и-проектов
DROP TABLE IF EXISTS mailboxes;

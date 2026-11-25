-- The tools a stdio server listed, kept with the entry they were listed by: a start of the application registers them
-- without starting the process. They are trusted only while they are the approved set (tools_hash). Text, not jsonb:
-- jsonb reorders the keys, and the hash of the kept tools must be the hash they were approved by.
-- spec: brief/skills-mcp.md#процессы-и-реестр-инструментов-по-контекстам
ALTER TABLE mcp_connections ADD COLUMN tools text;
ALTER TABLE mcp_connections ADD COLUMN tools_entry text;

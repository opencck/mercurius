-- The environments of the sandbox image (Node, Python, PHP, Go) the dependencies of an installed MCP were built in: a
-- new image with other versions builds them again by the kept steps.
-- spec: brief/skills-mcp.md#установка-и-обновление
ALTER TABLE mcp_installs ADD COLUMN environment text;

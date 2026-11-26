-- A server of the former registry that became an SSH entry of .mcp.json: moved once, never brought back, so an entry
-- the owner removed later stays removed. The table goes once every instance has moved.
-- spec: brief/servers.md#ssh-как-предустановленный-mcp
ALTER TABLE servers ADD COLUMN IF NOT EXISTS moved_at timestamptz;

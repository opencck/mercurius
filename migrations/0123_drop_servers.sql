-- The former registry of servers: every server became an SSH entry of .mcp.json (moved and checked on every instance),
-- the host keys live in ssh_host_keys. The secrets the servers named stay in the vault.
-- spec: brief/servers.md#ssh-как-предустановленный-mcp
DROP TABLE IF EXISTS servers;

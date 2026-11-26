-- The host keys of SSH entries of .mcp.json: by the connection (context|name) and the address the key was seen at, so
-- another address of the entry, or its server in between, has a key of its own. The key the owner trusted goes to the
-- process at its start; a key seen and not yet decided waits for the owner's card.
-- spec: brief/servers.md#ssh-как-предустановленный-mcp
CREATE TABLE IF NOT EXISTS ssh_host_keys (
    id bigserial PRIMARY KEY,
    server text NOT NULL,
    address text NOT NULL,
    host_key text,
    pending_host_key text,
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (server, address)
);

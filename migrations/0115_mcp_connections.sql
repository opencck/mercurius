-- MCP in contexts: a connection (an entry of .mcp.json of a context: the owners' area, a member, a project) leads to
-- an MCP server; its tools are offered only to runs of that context. The connection keeps its state for /mcp, the
-- metric and doctor. Installed MCP servers, the contexts they are allowed to and a secret bound to an MCP are prepared
-- here and filled by installation.
-- spec: brief/skills-mcp.md#три-слоя-установленный-mcp-запись-подключение
ALTER TABLE mcp_servers RENAME TO mcp_connections;
DROP INDEX IF EXISTS mcp_servers_name;

ALTER TABLE mcp_connections ADD COLUMN context text;
UPDATE mcp_connections SET context = 'member:' || coalesce((
    SELECT p.id FROM principals p
    WHERE p.kind = 'human' AND p.status = 'active' AND p.role IN (SELECT name FROM participant_roles WHERE 'owner' = ANY(permissions))
    ORDER BY p.id = (SELECT to_principal FROM ownership_transfers ORDER BY at DESC, id DESC LIMIT 1) DESC NULLS LAST, p.id
    LIMIT 1
), 0)::text;
ALTER TABLE mcp_connections ALTER COLUMN context SET NOT NULL;
CREATE UNIQUE INDEX mcp_connections_context_name ON mcp_connections (context, name);

ALTER TABLE mcp_connections DROP CONSTRAINT IF EXISTS mcp_servers_status_check;
UPDATE mcp_connections SET status = 'connected' WHERE status = 'active';
ALTER TABLE mcp_connections RENAME COLUMN status TO state;
ALTER TABLE mcp_connections ALTER COLUMN state SET DEFAULT 'pending';
ALTER TABLE mcp_connections ADD CONSTRAINT mcp_connections_state_check
    CHECK (state IN ('disabled', 'pending', 'connecting', 'connected', 'error', 'changed', 'names', 'update'));
ALTER TABLE mcp_connections RENAME COLUMN note TO reason;
ALTER TABLE mcp_connections ADD COLUMN next_try timestamptz;
ALTER TABLE mcp_connections ADD COLUMN install text;
ALTER TABLE mcp_connections ADD COLUMN entry_hash text;
ALTER TABLE mcp_connections ADD COLUMN approved_by bigint REFERENCES principals (id) ON DELETE SET NULL;
ALTER TABLE mcp_connections ADD COLUMN approved_at timestamptz;

CREATE TABLE mcp_installs (
    name text PRIMARY KEY CHECK (name ~ '^[a-z0-9][a-z0-9_-]{0,31}$'),
    source text NOT NULL,
    revision text,
    builtin boolean NOT NULL DEFAULT false,
    tools_hash text,
    tool_classes jsonb NOT NULL DEFAULT '{}',
    created_at timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE mcp_connections ADD CONSTRAINT mcp_connections_install_fk FOREIGN KEY (install) REFERENCES mcp_installs (name) ON DELETE SET NULL;

-- which contexts an installed MCP is allowed to: owners, member:<id>, project:<id>
CREATE TABLE mcp_install_contexts (
    install text NOT NULL REFERENCES mcp_installs (name) ON DELETE CASCADE,
    context text NOT NULL CHECK (context ~ '^(owners|member:[0-9]+|project:[0-9]+)$'),
    PRIMARY KEY (install, context)
);

-- a secret bound to an installed MCP instead of a site: only that MCP gets it in its entry
ALTER TABLE secrets ADD COLUMN mcp text;
ALTER TABLE secrets ADD CONSTRAINT secrets_site_or_mcp CHECK (site IS NULL OR mcp IS NULL);

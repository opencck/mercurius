-- Внешние MCP-серверы (HTTP): подключает и одобряет владелец; инструменты попадают в реестр агента.
-- spec: brief/skills-mcp.md#скиллы-и-mcp

CREATE TABLE mcp_servers (
    id bigserial PRIMARY KEY,
    name text NOT NULL,
    url text NOT NULL,
    -- секрет с токеном для заголовка Authorization: Bearer; null — без авторизации
    secret text,
    -- pending — добавлен, не одобрен; active — инструменты в реестре; disabled — выключен (владельцем или из-за смены описаний)
    status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'active', 'disabled')),
    -- хеш одобренного набора инструментов (имена, описания, схемы): изменение сервером замечается
    tools_hash text,
    note text NOT NULL DEFAULT '',
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX mcp_servers_name ON mcp_servers (name);

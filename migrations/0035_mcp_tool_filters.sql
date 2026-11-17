-- Какие инструменты MCP-сервера подключать: белый список (NULL — все; пустая строка — ни одного) и чёрный список.
-- Имена или маски через запятую, белый список важнее чёрного.
-- spec: BRIEF.md#скиллы-и-mcp

ALTER TABLE mcp_servers ADD COLUMN tools_include text;
ALTER TABLE mcp_servers ADD COLUMN tools_exclude text NOT NULL DEFAULT '';

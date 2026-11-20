-- Маршрут до сервера: напрямую, через VLESS приложения, через прокси из секрета, через другой сервер реестра.
-- spec: brief/servers.md#как-сделано-маршруты-серверов

-- null — напрямую; vless — через VLESS_URI; secret:<имя> — URI прокси в хранилище; via:<сервер> — через SSH другого сервера
ALTER TABLE servers ADD COLUMN route text;

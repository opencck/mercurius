-- Маршрут до сервера: напрямую, через VLESS приложения, через прокси из секрета, через другой сервер реестра.
-- spec: BRIEF.md#фаза-7-по-частям

-- null — напрямую; vless — через VLESS_URI; secret:<имя> — URI прокси в хранилище; via:<сервер> — через SSH другого сервера
ALTER TABLE servers ADD COLUMN route text;

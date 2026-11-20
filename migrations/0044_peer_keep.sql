-- Подключение к соседу, которое агент (или владелец) открыл, держится: сторож переоткрывает его после обрыва и перезапуска.
-- spec: brief/network.md#подключения-агентов-решение-владельца-2026-11-17

ALTER TABLE peers ADD COLUMN keep boolean NOT NULL DEFAULT false;

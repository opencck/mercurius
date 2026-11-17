-- Каким ключом агент представляется соседу: имя ключа в хранилище секретов, пусто — основной ключ.
-- spec: BRIEF.md#вход-по-websocket-и-несколько-ключей-решение-владельца-2026-11-17

ALTER TABLE peers ADD COLUMN identity text NOT NULL DEFAULT '';

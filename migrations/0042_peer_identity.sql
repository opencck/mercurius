-- Каким ключом агент представляется соседу: имя ключа в хранилище секретов, пусто — основной ключ.
-- spec: brief/network.md#вход-по-websocket-и-несколько-ключей

ALTER TABLE peers ADD COLUMN identity text NOT NULL DEFAULT '';

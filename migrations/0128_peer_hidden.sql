-- Agents the owner hid from the directory of the network: they are counted there, but not shown to others.
-- spec: brief/network.md#справочник-сети
CREATE TABLE peer_hidden (
    name text PRIMARY KEY,
    hidden_at timestamptz NOT NULL DEFAULT now()
);

-- A task may go to an agent that connected here (A2aLinks) and is not a neighbour in peers: the name is not a key.
-- spec: BRIEF.md#подключения-агентов-решение-владельца-2026-11-17
ALTER TABLE a2a_outgoing DROP CONSTRAINT IF EXISTS a2a_outgoing_peer_fkey;

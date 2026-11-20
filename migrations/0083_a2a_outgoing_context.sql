-- The context of a task at the neighbour (to answer its question there) and when it was read last (the longest
-- unread first, so a silent neighbour does not hold the others).
-- spec: brief/network.md#вызов-и-раздача-соседям
ALTER TABLE a2a_outgoing ADD COLUMN context_id text NOT NULL DEFAULT '';
ALTER TABLE a2a_outgoing ADD COLUMN polled_at timestamptz;

-- Who gave a task from the leader's side: its run (for the trace on both sides) and the task of its own neighbour,
-- when the leader's run is itself a task in a chain.
-- spec: brief/network.md#сеть-агентов-1
ALTER TABLE a2a_tasks ADD COLUMN leader_run bigint;
ALTER TABLE a2a_tasks ADD COLUMN parent_task text;

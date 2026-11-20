-- What a neighbour spent on a task, by the run of the leader that gave it: the leader's /trace shows it.
-- spec: brief/network.md#как-сделано-связь-двух-агентов — the spend of the follower comes back to the leader's trace in one unit
ALTER TABLE a2a_outgoing ADD COLUMN leader_run_id bigint;
ALTER TABLE a2a_outgoing ADD COLUMN remote_run bigint;
ALTER TABLE a2a_outgoing ADD COLUMN remote_tokens integer NOT NULL DEFAULT 0;
ALTER TABLE a2a_outgoing ADD COLUMN remote_usd double precision;
CREATE INDEX a2a_outgoing_leader_run ON a2a_outgoing (leader_run_id) WHERE leader_run_id IS NOT NULL;

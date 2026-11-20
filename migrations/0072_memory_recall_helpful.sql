-- Whether the answer a recalled fact went into was helped by it: the agent's memory_feedback marks the recall of its
-- own run. The owner sees how a fact served before changing or forgetting it.
-- spec: brief/memory.md#память-слои
ALTER TABLE memory_recalls ADD COLUMN helpful boolean;
CREATE INDEX memory_recalls_by_fact ON memory_recalls (fact_id);

-- The run a decision about the model is for. A switch after a failure is made inside the run and names it; a choice
-- made before the run started (the router of a conversation, the profile of a worker) is taken by the run when it
-- starts: the latest decision of the conversation for its model, made in the last two minutes and not taken yet. A
-- worker looks in the conversation of the run that delegated to it.
-- spec: BRIEF.md#выбор-моделей-под-задачу — every decision about the model is kept and shown in /trace
ALTER TABLE routing_decisions ADD COLUMN run_id bigint REFERENCES runs (id) ON DELETE CASCADE;
CREATE INDEX routing_decisions_run ON routing_decisions (run_id) WHERE run_id IS NOT NULL;

CREATE FUNCTION routing_decision_takes_run() RETURNS trigger AS $$
BEGIN
    UPDATE routing_decisions SET run_id = NEW.id WHERE id = (
        SELECT d.id FROM routing_decisions d
        WHERE d.run_id IS NULL
          AND d.model = NEW.model
          AND d.created_at > now() - interval '2 minutes'
          AND d.conversation_id = coalesce((SELECT p.conversation_id FROM runs p WHERE p.id = NEW.parent_run_id), NEW.conversation_id)
        ORDER BY d.id DESC
        LIMIT 1
    );
    RETURN NEW;
END
$$ LANGUAGE plpgsql;

CREATE TRIGGER routing_decision_takes_run AFTER INSERT ON runs FOR EACH ROW EXECUTE FUNCTION routing_decision_takes_run();

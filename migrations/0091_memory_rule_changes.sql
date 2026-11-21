-- The section «Правила» of each MEMORY.md as last seen, and its changes with the text before them, while the owner keeps
-- the notice of changes on: a change can be taken back by its button. With the notice off nothing is kept.
-- spec: brief/memory.md#сообщение-об-изменении-правил
CREATE TABLE memory_rule_snapshots (
    file text PRIMARY KEY,
    rules text NOT NULL,
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE memory_rule_changes (
    id bigserial PRIMARY KEY,
    holder bigint NOT NULL,
    path text NOT NULL,
    kind text NOT NULL,
    project_id bigint,
    before text NOT NULL,
    after text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    undone_at timestamptz
);

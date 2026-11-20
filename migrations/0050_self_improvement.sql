-- Self-improvement under the owner: the agent's proposals and the versions of the notes to the system prompt.
-- spec: brief/self-improvement.md#самоулучшение
CREATE TABLE improvements (
    id bigserial PRIMARY KEY,
    kind text NOT NULL CHECK (kind IN ('prompt', 'setting', 'idea')),
    title text NOT NULL,
    body text NOT NULL,
    setting_key text,
    reason text NOT NULL DEFAULT '',
    status text NOT NULL DEFAULT 'proposed' CHECK (status IN ('proposed', 'checking', 'accepted', 'rejected', 'failed')),
    result text,
    created_at timestamptz NOT NULL DEFAULT now(),
    decided_at timestamptz
);

-- A version never changes; the active one is a pointer that moves (a rollback moves it back).
CREATE TABLE prompt_notes (
    version serial PRIMARY KEY,
    text text NOT NULL,
    reason text NOT NULL DEFAULT '',
    active boolean NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX prompt_notes_one_active ON prompt_notes (active) WHERE active;

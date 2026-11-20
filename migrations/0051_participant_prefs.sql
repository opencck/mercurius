-- A participant's own time zone and quiet hours; NULL — the owner's from .env, '' quiet hours — none.
-- spec: brief/participants.md#несколько-пользователей
CREATE TABLE participant_prefs (
    principal_id bigint PRIMARY KEY REFERENCES principals (id) ON DELETE CASCADE,
    timezone text,
    quiet_hours text,
    updated_at timestamptz NOT NULL DEFAULT now()
);

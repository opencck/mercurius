-- The context a private conversation works in: null — the member's own, 'owners' — the shared context of the
-- owners of the agent, 'project:<id>' — a project; /new opens a conversation without it.
-- spec: brief/participants.md#контекст-беседы-в-личном-чате
ALTER TABLE conversations ADD COLUMN context text;

-- What the model thought before a turn with tool calls: Kimi refuses the next request of the run without it.
-- Null — no reasoning, or a turn without calls.
-- spec: brief/models.md#kimi-ключ-api-и-подписка
ALTER TABLE messages ADD COLUMN reasoning text;

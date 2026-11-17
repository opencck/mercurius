-- Личные сообщения, которые агент отправил участнику по чьей-то просьбе и на которые ждёт ответа: следующее сообщение
-- участника в личном чате возвращается в беседу, откуда его спросили.
-- spec: BRIEF.md#личные-сообщения-участникам

CREATE TABLE message_relays (
    id bigserial PRIMARY KEY,
    channel text NOT NULL,
    recipient_chat_id text NOT NULL,
    recipient_principal_id bigint NOT NULL REFERENCES principals (id) ON DELETE CASCADE,
    origin_conversation_id bigint NOT NULL REFERENCES conversations (id) ON DELETE CASCADE,
    origin_principal_id bigint NOT NULL REFERENCES principals (id) ON DELETE CASCADE,
    origin_run_id bigint,
    question text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    answered_at timestamptz
);

CREATE INDEX message_relays_open ON message_relays (channel, recipient_chat_id, id) WHERE answered_at IS NULL;

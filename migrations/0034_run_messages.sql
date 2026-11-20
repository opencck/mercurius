-- Какой запуск написал сообщение агента в чате: ответ на сообщение агента называет свой запуск, и агент смотрит
-- расход нужного запуска, а не угадывает.
-- spec: brief/operations.md#настройки-агента

CREATE TABLE run_messages (
    channel text NOT NULL,
    chat_id text NOT NULL,
    message_id text NOT NULL,
    run_id bigint NOT NULL REFERENCES runs (id) ON DELETE CASCADE,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (channel, chat_id, message_id)
);

-- Потоки мест для агентов сети: владелец агента даёт агенту поток чата или темы, агент подписывается и слушает его
-- по WebSocket. Отзыв — удаление строки: идущая подписка кончается вместе с ней.
-- spec: brief/network.md#потоки-мест-для-агентов

CREATE TABLE IF NOT EXISTS place_streams (
    id bigserial PRIMARY KEY,
    principal_id bigint NOT NULL REFERENCES principals (id) ON DELETE CASCADE,
    channel text NOT NULL,
    chat_id text NOT NULL,
    thread_id text NOT NULL DEFAULT '',
    title text NOT NULL DEFAULT '',
    subscribed boolean NOT NULL DEFAULT false,
    created_by bigint REFERENCES principals (id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX IF NOT EXISTS place_streams_once ON place_streams (principal_id, channel, chat_id, thread_id);
CREATE INDEX IF NOT EXISTS place_streams_place ON place_streams (channel, chat_id) WHERE subscribed;

-- Каналы, где бот — администратор: права из Telegram и история постов, чтобы агент видел, что уже опубликовано.
-- spec: brief/channels.md#канал-паблик

CREATE TABLE chat_channels (
    channel text NOT NULL,
    chat_id text NOT NULL,
    title text NOT NULL DEFAULT '',
    -- права бота: публиковать, править и удалять посты
    can_post boolean NOT NULL DEFAULT false,
    can_edit boolean NOT NULL DEFAULT false,
    can_delete boolean NOT NULL DEFAULT false,
    -- false — бот больше не администратор: канал недоступен
    available boolean NOT NULL DEFAULT false,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (channel, chat_id)
);

CREATE TABLE channel_posts (
    channel text NOT NULL,
    chat_id text NOT NULL,
    message_id text NOT NULL,
    text text NOT NULL DEFAULT '',
    posted_at timestamptz NOT NULL,
    edited_at timestamptz,
    PRIMARY KEY (channel, chat_id, message_id),
    FOREIGN KEY (channel, chat_id) REFERENCES chat_channels (channel, chat_id) ON DELETE CASCADE
);
CREATE INDEX channel_posts_recent ON channel_posts (channel, chat_id, posted_at DESC);

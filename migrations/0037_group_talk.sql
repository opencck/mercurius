-- Наблюдаемый разговор группы: что писали без обращения к агенту. Ключ — чат и тема форума ('' — чат целиком),
-- в запуск идут последние строки за сутки; старше недели — удаляются. Переживает перезапуск.
-- spec: BRIEF.md#группа

CREATE TABLE group_talk (
    id bigserial PRIMARY KEY,
    channel text NOT NULL,
    chat_id text NOT NULL,
    thread_id text NOT NULL DEFAULT '',
    line text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX group_talk_recent ON group_talk (channel, chat_id, thread_id, id DESC);

-- Группы, куда добавили бота: работает только в одобренных владельцем; режим — по упоминанию или свободно.
-- spec: BRIEF.md#telegram-группы-каналы-и-темы

CREATE TABLE chat_groups (
    channel text NOT NULL,
    chat_id text NOT NULL,
    title text NOT NULL DEFAULT '',
    -- pending — ждёт решения владельца; approved — работает; refused — бот вышел
    status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'refused')),
    -- mention — отвечает на упоминание и ответ себе; free — сам решает, когда ответить
    mode text NOT NULL DEFAULT 'mention' CHECK (mode IN ('mention', 'free')),
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (channel, chat_id)
);

-- Группа — форум с темами: у проектов там свои темы. Telegram сообщает это в каждом сообщении (chat.is_forum).
-- spec: BRIEF.md#форум-темы--проекты

ALTER TABLE chat_groups ADD COLUMN forum boolean NOT NULL DEFAULT false;

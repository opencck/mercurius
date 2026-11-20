-- У темы форума своя беседа и свой активный проект. Пустая строка — личный чат, обычная группа и тема General.
-- spec: brief/channels.md#форум-темы--проекты

ALTER TABLE conversations ADD COLUMN thread_id text NOT NULL DEFAULT '';
DROP INDEX conversations_one_open_per_chat;
CREATE UNIQUE INDEX conversations_one_open_per_chat ON conversations (channel, chat_id, thread_id) WHERE closed_at IS NULL;

ALTER TABLE chat_projects ADD COLUMN thread_id text NOT NULL DEFAULT '';
ALTER TABLE chat_projects DROP CONSTRAINT chat_projects_pkey;
ALTER TABLE chat_projects ADD PRIMARY KEY (channel, chat_id, thread_id);

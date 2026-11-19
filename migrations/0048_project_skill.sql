-- A skill bound to a project: loaded into the context of the project's chats and forum topics.
-- spec: BRIEF.md#форум-темы--проекты — a skill of the project in its topic
ALTER TABLE projects ADD COLUMN skill text NOT NULL DEFAULT '';

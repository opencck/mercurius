-- У проекта есть хозяин: NULL — владелец агента (все прежние проекты), иначе участник. Пользователь видит и ведёт
-- только свои проекты; имя уникально в пределах хозяина.
-- spec: brief/participants.md#несколько-пользователей

ALTER TABLE projects ADD COLUMN owner_id bigint REFERENCES principals (id) ON DELETE CASCADE;

DROP INDEX projects_name;
CREATE UNIQUE INDEX projects_owner_name ON projects (coalesce(owner_id, 0), lower(name));

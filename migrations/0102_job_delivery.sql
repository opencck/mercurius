-- Место итога задания: '' — как прежде (тема проекта, иначе чат, где поставлено); 'private' — личное место создателя;
-- 'chat' — названный чат или тема канала.
-- spec: brief/scheduler.md#место-итога-задания

ALTER TABLE jobs ADD COLUMN IF NOT EXISTS deliver text NOT NULL DEFAULT '';
ALTER TABLE jobs ADD COLUMN IF NOT EXISTS deliver_chat text NOT NULL DEFAULT '';
ALTER TABLE jobs ADD COLUMN IF NOT EXISTS deliver_thread text NOT NULL DEFAULT '';

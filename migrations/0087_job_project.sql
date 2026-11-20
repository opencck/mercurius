-- A job of a project: it runs in the name of the project's owner, with the project's files, skills and browser
-- profile, and reports to the project's topic or to its owner.
-- spec: brief/scheduler.md#задания-проекта
ALTER TABLE jobs ADD COLUMN project_id bigint REFERENCES projects (id) ON DELETE CASCADE;
CREATE INDEX jobs_project ON jobs (project_id) WHERE project_id IS NOT NULL;

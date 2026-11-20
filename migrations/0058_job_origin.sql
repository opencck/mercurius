-- A job set up by a run that had read outside data carries that mark to its runs; the owner may trust the source of a
-- job, and then its runs are not marked and saved grants apply.
-- spec: brief/agent-loop.md#запуск-начатый-внешними-данными — a run started by outside data uses no saved grant
ALTER TABLE jobs ADD COLUMN origin text;
ALTER TABLE jobs ADD COLUMN trusted boolean NOT NULL DEFAULT false;

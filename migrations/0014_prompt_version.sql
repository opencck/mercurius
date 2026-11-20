-- Версия системного промпта, с которым открыт диалог: трассы и оценки группируются по ней.
-- spec: brief/operations.md#настройки-агента

ALTER TABLE conversations ADD COLUMN prompt_version integer;

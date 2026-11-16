-- Версия системного промпта, с которым открыт диалог: трассы и оценки группируются по ней.
-- spec: BRIEF.md#трассировка-и-метрики

ALTER TABLE conversations ADD COLUMN prompt_version integer;

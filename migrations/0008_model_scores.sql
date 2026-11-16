-- Индексы Artificial Analysis моделей каталога: профиль модели для выбора под задачу.
-- spec: BRIEF.md#часть-2--профиль-задачи-выбор-модели-каскад

ALTER TABLE model_catalog
    ADD COLUMN intelligence_index double precision,
    ADD COLUMN coding_index double precision,
    ADD COLUMN agentic_index double precision;

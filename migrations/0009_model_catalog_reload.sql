-- Каталог моделей, загруженный до 0008, не содержит индексов: помечаем его устаревшим, чтобы модуль каталога
-- перезагрузил его при старте, а не через сутки.
-- spec: BRIEF.md#часть-2--профиль-задачи-выбор-модели-каскад

UPDATE model_catalog SET fetched_at = fetched_at - interval '2 days'
WHERE intelligence_index IS NULL AND coding_index IS NULL AND agentic_index IS NULL;

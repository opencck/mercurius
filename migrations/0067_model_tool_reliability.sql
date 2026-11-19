-- Whether the catalog saw the tool calls of a model pass their schemas often enough (OpenRouter's share of good tool
-- calls, at least 90%); null — not known. A part with tools does not get a model marked false.
-- spec: BRIEF.md#выбор-моделей-под-задачу
ALTER TABLE model_catalog ADD COLUMN tool_reliable boolean;

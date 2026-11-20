-- Whether the catalog saw the tool calls of a model pass their schemas often enough (OpenRouter's share of good tool
-- calls, at least 90%); null — not known. A part with tools does not get a model marked false.
-- spec: brief/models.md#надёжность-инструментов-и-решения-о-модели
ALTER TABLE model_catalog ADD COLUMN tool_reliable boolean;

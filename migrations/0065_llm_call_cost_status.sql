-- How sure the cost of a call is: reported by OpenRouter (actual), estimated by catalog prices for other providers
-- (estimated), or not known.
-- spec: brief/models.md#провайдеры-моделей-openrouter-и-openai — only OpenRouter reports a cost
ALTER TABLE llm_calls ADD COLUMN cost_status text NOT NULL DEFAULT 'unknown' CHECK (cost_status IN ('actual', 'estimated', 'unknown'));

-- calls before: a model of another provider is "provider:id" with no slash before the colon
UPDATE llm_calls SET cost_status = CASE
    WHEN cost_usd IS NULL THEN 'unknown'
    WHEN model ~ '^[a-z0-9_-]+:' AND model !~ '^openrouter:' THEN 'estimated'
    ELSE 'actual'
END;

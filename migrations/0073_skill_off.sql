-- A skill the owner turned off with /skill <name> off: out of the index, its files untouched.
-- spec: brief/skills-mcp.md#учёт-использования-журнал-правок-и-обновление-скиллов
ALTER TABLE skill_usage ADD COLUMN off boolean NOT NULL DEFAULT false;

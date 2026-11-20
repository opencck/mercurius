-- Skills are folders now; the catalog in the base was moved out on every instance and no code reads it since the
-- release before this one (the schema changes in two steps).
-- spec: brief/skills-mcp.md#скиллы--файлы-встроенные-участника-и-проекта — no skills in the base
DROP TABLE IF EXISTS skill_files;
DROP TABLE IF EXISTS skill_versions;
DROP TABLE IF EXISTS skills;

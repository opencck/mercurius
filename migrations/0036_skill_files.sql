-- Файлы скилла (references/, scripts/ и др.) — у каждой версии свои: откат возвращает и их. Только текст UTF-8.
-- spec: brief/skills-mcp.md#скиллы-и-mcp

CREATE TABLE skill_files (
    version_id bigint NOT NULL REFERENCES skill_versions (id) ON DELETE CASCADE,
    path text NOT NULL,
    content text NOT NULL,
    PRIMARY KEY (version_id, path)
);

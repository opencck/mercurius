-- Как участник хочет обрабатывать каждый вид файлов: сразу, по запросу или никак; предпочтительная модель из списка
-- владельца и свои принципы выбора текстом. Нет строки — умолчание владельца.
-- spec: brief/internet.md#входящие-вложения

CREATE TABLE media_prefs (
    principal_id bigint NOT NULL REFERENCES principals (id) ON DELETE CASCADE,
    kind text NOT NULL CHECK (kind IN ('voice', 'audio', 'image', 'video', 'text', 'office')),
    mode text CHECK (mode IN ('auto', 'demand', 'off')),
    model text,
    rules text NOT NULL DEFAULT '',
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (principal_id, kind)
);

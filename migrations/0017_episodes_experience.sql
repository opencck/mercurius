-- Эпизоды: полнотекстовый поиск по сообщениям бесед. Опыт: исходы частей, порученных воркерам.
-- spec: BRIEF.md#память-слои — слой 6 (эпизоды) и слой 7 (опыт)

-- Конфигурация russian: русские слова — русский стеммер, латиница — английский. Ищется текст пользователя и ответа
-- без блока вспомненной памяти (он дописан после текста пользователя); результаты инструментов не ищутся.
ALTER TABLE messages
    ADD COLUMN search tsvector GENERATED ALWAYS AS (
        CASE WHEN role IN ('user', 'assistant')
            THEN to_tsvector('russian'::regconfig, left(split_part(content, E'\n\n<untrusted-data source="memory">', 1), 100000))
        END
    ) STORED;

CREATE INDEX messages_search ON messages USING gin (search);

CREATE TABLE experiences (
    id bigserial PRIMARY KEY,
    -- беседа оркестратора: по ней решается, кому виден опыт
    conversation_id bigint NOT NULL REFERENCES conversations (id) ON DELETE CASCADE,
    principal_id bigint REFERENCES principals (id) ON DELETE SET NULL,
    -- последний запуск воркера по части (повтор каскада, если был)
    run_id bigint REFERENCES runs (id) ON DELETE SET NULL,
    goal text NOT NULL,
    role text NOT NULL,
    -- оценки по осям, только заметные
    profile jsonb,
    model text NOT NULL,
    -- модель первой попытки, когда был каскад
    first_model text,
    -- done | failed | limit | stopped
    status text NOT NULL,
    -- вердикт проверяющего: принято | отклонено | не проверено
    verdict text,
    reason text,
    turns integer NOT NULL DEFAULT 0,
    tokens integer NOT NULL DEFAULT 0,
    duration_ms bigint NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now(),
    search tsvector GENERATED ALWAYS AS (to_tsvector('russian'::regconfig, goal)) STORED
);

CREATE INDEX experiences_search ON experiences USING gin (search);
CREATE INDEX experiences_by_time ON experiences (created_at DESC);

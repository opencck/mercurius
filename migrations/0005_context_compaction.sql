-- Сжатие контекста: резюме ранней части диалога, обрезанные результаты инструментов, неудачи резюме.
-- spec: brief/agent-loop.md#модели — сжатие на месте: ничего не удаляется, старые сообщения помечаются

ALTER TABLE messages
    -- текст, который идёт модели вместо content (обрезанный старый результат инструмента)
    ADD COLUMN compact_content text;

ALTER TABLE conversations
    ADD COLUMN summary text,
    -- id последнего сообщения, вошедшего в резюме; модели идут только сообщения после него
    ADD COLUMN summary_upto bigint NOT NULL DEFAULT 0,
    ADD COLUMN compaction_failures integer NOT NULL DEFAULT 0,
    ADD COLUMN compaction_cooldown_until timestamptz;

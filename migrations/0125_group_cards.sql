-- Карточка группы и прежний номер. card — сообщение карточки «Меня добавили в группу» у владельца, пока группа ждёт
-- решения: при переезде группы на новый номер устаревшая карточка удаляется. former_chat_id — номер группы до того, как
-- Telegram сделал её супергруппой: кнопка старой карточки решает за новый номер.
-- spec: brief/channels.md#как-сделано-группы

ALTER TABLE chat_groups ADD COLUMN card text;
ALTER TABLE chat_groups ADD COLUMN former_chat_id text;
CREATE INDEX chat_groups_former ON chat_groups (channel, former_chat_id) WHERE former_chat_id IS NOT NULL;

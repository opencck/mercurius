-- Свой дневной лимит токенов свободного режима у группы: пусто — по умолчанию из GROUP_FREE_DAILY_TOKENS, 0 — без лимита.
-- Владелец меняет его кнопками карточки, которая приходит, когда группа достигла лимита.
-- spec: brief/channels.md#группа

ALTER TABLE chat_groups ADD COLUMN daily_tokens integer CHECK (daily_tokens IS NULL OR daily_tokens >= 0);

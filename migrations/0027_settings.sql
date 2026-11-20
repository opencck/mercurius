-- Настройки агента, которые владелец меняет в диалоге: поверх .env (там — только связь с Telegram и служебное).
-- Значения секретных настроек лежат в хранилище секретов под именем setting.<ключ>, здесь — только отметка.
-- spec: brief/operations.md#настройки-агента

CREATE TABLE settings (
    key text PRIMARY KEY,
    value text,
    secret boolean NOT NULL DEFAULT false,
    updated_by bigint REFERENCES principals (id) ON DELETE SET NULL,
    updated_at timestamptz NOT NULL DEFAULT now()
);

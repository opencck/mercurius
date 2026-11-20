-- Обязательство доставить итоговый ответ: записывается до отправки, отмечается частями; ответ, не дошедший до чата
-- из-за сбоя или перезапуска, отправляется снова при старте с той части, что не дошла.
-- spec: brief/agent-loop.md#доставка-итогового-ответа

CREATE TABLE delivery_obligations (
    id bigserial PRIMARY KEY,
    run_id bigint REFERENCES runs (id) ON DELETE SET NULL,
    channel text NOT NULL,
    chat_id text NOT NULL,
    thread_id text,
    reply_to text,
    text text NOT NULL,
    parse_mode text NOT NULL,
    -- частей уже доставлено
    sent integer NOT NULL DEFAULT 0,
    -- pending — ещё не отправлялся; attempting — отправка шла; delivered; failed — отказ; abandoned — брошен
    status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'attempting', 'delivered', 'failed', 'abandoned')),
    attempts integer NOT NULL DEFAULT 0,
    last_error text NOT NULL DEFAULT '',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX delivery_obligations_open ON delivery_obligations (status) WHERE status IN ('pending', 'attempting', 'failed');

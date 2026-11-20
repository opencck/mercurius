-- Сеть агентов по A2A v1.0: известные агенты по отпечатку ключа, входящие задачи, реестр соседей, отправленные задачи.
-- spec: brief/network.md#как-сделано-связь-двух-агентов

-- Агент, которому владелец разрешил подключаться: узнаётся по отпечатку открытого ключа ECDSA (подпись каждого
-- запроса), отзыв касается только его.
CREATE TABLE agent_keys (
    principal_id bigint PRIMARY KEY REFERENCES principals (id) ON DELETE CASCADE,
    name text NOT NULL UNIQUE,
    fingerprint text NOT NULL UNIQUE,
    created_at timestamptz NOT NULL DEFAULT now(),
    revoked_at timestamptz
);

-- Одноразовые числа подписанных запросов: повтор перехваченного запроса не проходит.
CREATE TABLE a2a_nonces (
    nonce text PRIMARY KEY,
    seen_at timestamptz NOT NULL DEFAULT now()
);

-- Входящие задачи: повтор messageId от того же соседа возвращает ту же задачу (и после перезапуска).
CREATE TABLE a2a_tasks (
    id text PRIMARY KEY,
    principal_id bigint NOT NULL REFERENCES principals (id) ON DELETE CASCADE,
    context_id text NOT NULL,
    message_id text NOT NULL,
    state text NOT NULL,
    run_id bigint,
    result text,
    -- цепочка делегирования: глубина и пройденные экземпляры (защита от петель)
    depth integer NOT NULL DEFAULT 0,
    path jsonb NOT NULL DEFAULT '[]',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (principal_id, message_id)
);

CREATE INDEX a2a_tasks_context ON a2a_tasks (principal_id, context_id);
CREATE INDEX a2a_tasks_run ON a2a_tasks (run_id);
CREATE INDEX a2a_tasks_open ON a2a_tasks (updated_at) WHERE state IN ('TASK_STATE_SUBMITTED', 'TASK_STATE_WORKING');

-- Соседи, к которым подключается сам агент: токен входа — в хранилище секретов по имени; открытый ключ закреплён при
-- добавлении, ответы соседа проверяются по нему.
CREATE TABLE peers (
    name text PRIMARY KEY,
    url text NOT NULL,
    secret text NOT NULL,
    public_key text NOT NULL,
    card jsonb,
    created_at timestamptz NOT NULL DEFAULT now()
);

-- Задачи, отданные соседям: опрашиваются, итог доставляется в чат, откуда задача пришла.
CREATE TABLE a2a_outgoing (
    id bigserial PRIMARY KEY,
    peer text NOT NULL REFERENCES peers (name) ON DELETE CASCADE,
    remote_id text NOT NULL,
    principal_id bigint NOT NULL REFERENCES principals (id) ON DELETE CASCADE,
    channel text NOT NULL,
    chat_id text NOT NULL,
    task text NOT NULL,
    state text NOT NULL,
    result text,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX a2a_outgoing_open ON a2a_outgoing (id) WHERE state NOT IN ('TASK_STATE_COMPLETED', 'TASK_STATE_FAILED', 'TASK_STATE_CANCELED', 'TASK_STATE_REJECTED');

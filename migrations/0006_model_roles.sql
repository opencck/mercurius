-- Каталог моделей по провайдерам, таблица ролей, журнал выбора моделей.
-- spec: brief/agent-loop.md#модели — модель = провайдер + идентификатор; роли меняют агент, владелец и те, кому он разрешил

CREATE TABLE model_catalog (
    provider text NOT NULL,
    id text NOT NULL,
    name text NOT NULL,
    context_length integer,
    input_price double precision,
    output_price double precision,
    input_modalities jsonb NOT NULL DEFAULT '[]',
    supported_parameters jsonb NOT NULL DEFAULT '[]',
    max_completion_tokens integer,
    fetched_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (provider, id)
);

CREATE TABLE model_roles (
    role text PRIMARY KEY CHECK (role IN ('cheap', 'main', 'strong', 'media')),
    provider text NOT NULL,
    model text NOT NULL,
    reason text NOT NULL,
    -- seed — начальная таблица; agent — агент или участник с правом; owner — зафиксировал владелец
    set_by text NOT NULL CHECK (set_by IN ('seed', 'agent', 'owner')),
    set_by_principal bigint REFERENCES principals (id) ON DELETE SET NULL,
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE routing_decisions (
    id bigserial PRIMARY KEY,
    conversation_id bigint REFERENCES conversations (id) ON DELETE CASCADE,
    class text NOT NULL,
    -- как определён класс: heuristic, model, fallback
    method text NOT NULL,
    role text NOT NULL,
    model text NOT NULL,
    reason text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);

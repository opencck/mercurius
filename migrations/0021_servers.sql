-- Хранилище секретов, реестр серверов, журнал команд с цепочкой хешей.
-- spec: brief/servers.md#фаза-7-по-частям

-- Секрет хранится зашифрованным (libsodium secretbox, мастер-ключ SECRETS_KEY в .env); имя видно, значение — нет.
CREATE TABLE secrets (
    id bigserial PRIMARY KEY,
    name text NOT NULL,
    -- password | private_key | token
    kind text NOT NULL CHECK (kind IN ('password', 'private_key', 'token')),
    nonce bytea NOT NULL,
    ciphertext bytea NOT NULL,
    -- автор; права по умолчанию — автор и владелец
    principal_id bigint REFERENCES principals (id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX secrets_name ON secrets (lower(name));

CREATE TABLE servers (
    id bigserial PRIMARY KEY,
    name text NOT NULL,
    host text NOT NULL,
    port integer NOT NULL DEFAULT 22 CHECK (port BETWEEN 1 AND 65535),
    username text NOT NULL,
    -- секрет для входа: пароль или закрытый ключ
    secret_id bigint REFERENCES secrets (id) ON DELETE SET NULL,
    -- группа для политики команд (prod, test…)
    server_group text NOT NULL DEFAULT 'default',
    -- закреплённый отпечаток ключа хоста (SHA256:…); null — ещё не подтверждён владельцем
    host_key text,
    -- отпечаток, увиденный при первом подключении и ждущий решения владельца
    pending_host_key text,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX servers_name ON servers (lower(name));

-- Журнал команд: каждая запись связана с предыдущей хешем, правка задним числом рвёт цепочку.
CREATE TABLE command_audit (
    id bigserial PRIMARY KEY,
    at timestamptz NOT NULL DEFAULT now(),
    principal_id bigint REFERENCES principals (id) ON DELETE SET NULL,
    run_id bigint,
    server text NOT NULL,
    -- команда после маскирования секретов
    command text NOT NULL,
    -- read | change | privileged | never
    class text NOT NULL,
    -- start (перед выполнением) | done | failed | refused
    event text NOT NULL,
    exit_code integer,
    duration_ms integer,
    error text,
    prev_hash text NOT NULL,
    hash text NOT NULL
);

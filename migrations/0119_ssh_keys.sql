-- The keys of a participant's ~/.ssh as the agent keeps them: name, fingerprint, public key and the secret with the
-- key's passphrase. The private key stays in the sandbox and is never stored here.
-- spec: brief/servers.md#ключи-ssh-в-песочнице
CREATE TABLE IF NOT EXISTS ssh_keys (
    holder integer NOT NULL,
    name text NOT NULL,
    fingerprint text NOT NULL,
    public_key text NOT NULL,
    passphrase_secret text,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (holder, name)
);

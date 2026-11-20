-- Ownership passed from one participant to another. What a former owner kept as owner stays theirs: the new owner does
-- not see it.
-- spec: brief/participants.md#несколько-пользователей — the owner passes ownership on, the personal stays with the former owner
CREATE TABLE ownership_transfers (
    id bigserial PRIMARY KEY,
    from_principal bigint NOT NULL REFERENCES principals (id) ON DELETE CASCADE,
    to_principal bigint NOT NULL REFERENCES principals (id) ON DELETE CASCADE,
    at timestamptz NOT NULL DEFAULT now()
);

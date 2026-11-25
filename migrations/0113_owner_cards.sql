-- A card or a notice for the owners goes to every owner: one card, a copy in each owner's private chat. A decision on
-- any copy edits every copy.
-- spec: brief/participants.md#свои-роли
CREATE TABLE owner_cards (
    id bigserial PRIMARY KEY,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE owner_card_copies (
    card_id bigint NOT NULL REFERENCES owner_cards (id) ON DELETE CASCADE,
    chat_id text NOT NULL,
    message_id text NOT NULL,
    PRIMARY KEY (chat_id, message_id)
);
CREATE INDEX owner_card_copies_card ON owner_card_copies (card_id);

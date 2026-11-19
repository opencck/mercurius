-- Whether a conversation is a private chat: kept, so a run after a restart does not lose the memory of a private
-- chat until its next message; null — not known, the narrowest audience (a group).
-- spec: BRIEF.md#контекст-по-месту-и-аудитории
ALTER TABLE conversations ADD COLUMN private_chat boolean;

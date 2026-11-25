-- A role has permissions: the column of the role catalog is named so.
-- spec: brief/participants.md#свои-роли
ALTER TABLE participant_roles RENAME COLUMN powers TO permissions;

-- The owner of the agent is any person whose role has the permission owner: the starting roles are admin (with
-- owner), user and reader. The members are moved to these roles at start (OwnerRoleUpgrade): only the code knows
-- TELEGRAM_ADMIN_ID.
-- spec: brief/participants.md#свои-роли
DROP INDEX IF EXISTS principals_single_owner;

INSERT INTO participant_roles (name, position, permissions) VALUES ('admin', 10, '{owner}')
    ON CONFLICT (name) DO UPDATE SET permissions = '{owner}';

INSERT INTO participant_roles (name, title, position, permissions, policy)
    SELECT 'reader', title, position, permissions, policy FROM participant_roles WHERE name = 'visitor'
    ON CONFLICT (name) DO NOTHING;
UPDATE principals SET role = 'reader' WHERE role = 'visitor';
DELETE FROM participant_roles WHERE name = 'visitor';

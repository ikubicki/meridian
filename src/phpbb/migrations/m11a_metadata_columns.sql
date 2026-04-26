-- M11a: Add metadata column for plugin-driven metadata storage
-- Stores JSON-encoded plugin metadata per entity

ALTER TABLE phpbb_posts       ADD COLUMN metadata MEDIUMTEXT NULL DEFAULT NULL;
ALTER TABLE phpbb_topics      ADD COLUMN metadata MEDIUMTEXT NULL DEFAULT NULL;
ALTER TABLE phpbb_forums      ADD COLUMN metadata MEDIUMTEXT NULL DEFAULT NULL;
ALTER TABLE phpbb_users       ADD COLUMN metadata MEDIUMTEXT NULL DEFAULT NULL;
ALTER TABLE phpbb_attachments ADD COLUMN metadata MEDIUMTEXT NULL DEFAULT NULL;

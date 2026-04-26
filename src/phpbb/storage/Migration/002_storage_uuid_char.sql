-- This file is part of the phpBB4 "Meridian" package.
-- @copyright (c) Irek Kubicki <phpbb@codebuilders.pl>
-- @license GNU General Public License, version 2 (GPL-2.0)

-- Migration: BINARY(16) → CHAR(32) UUID hex string (without dashes)
-- Safe 3-step migration to preserve existing data

ALTER TABLE phpbb_stored_files
	ADD COLUMN id_new        CHAR(32)        NULL AFTER id,
	ADD COLUMN parent_id_new CHAR(32)        NULL AFTER parent_id;

UPDATE phpbb_stored_files SET id_new = LOWER(HEX(id));
UPDATE phpbb_stored_files SET parent_id_new = LOWER(HEX(parent_id)) WHERE parent_id IS NOT NULL;

ALTER TABLE phpbb_stored_files
	DROP PRIMARY KEY,
	DROP COLUMN id,
	CHANGE COLUMN id_new id CHAR(32) NOT NULL,
	ADD PRIMARY KEY (id),
	DROP COLUMN parent_id,
	CHANGE COLUMN parent_id_new parent_id CHAR(32) DEFAULT NULL;

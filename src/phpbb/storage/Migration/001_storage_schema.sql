-- This file is part of the phpBB4 "Meridian" package.
-- @copyright (c) Irek Kubicki <phpbb@codebuilders.pl>
-- @license GNU General Public License, version 2 (GPL-2.0)

CREATE TABLE phpbb_stored_files (
	id            CHAR(32)        NOT NULL,
	asset_type    VARCHAR(20)     NOT NULL,
	visibility    VARCHAR(10)     NOT NULL,
	original_name VARCHAR(255)    NOT NULL,
	physical_name VARCHAR(255)    NOT NULL,
	mime_type     VARCHAR(127)    NOT NULL,
	filesize      INT UNSIGNED    NOT NULL,
	checksum      CHAR(64)        NOT NULL,
	is_orphan     TINYINT(1)      NOT NULL DEFAULT 1,
	parent_id     CHAR(32)        DEFAULT NULL,
	variant_type  VARCHAR(20)     DEFAULT NULL,
	uploader_id   INT UNSIGNED    NOT NULL,
	forum_id      INT UNSIGNED    NOT NULL DEFAULT 0,
	created_at    INT UNSIGNED    NOT NULL,
	claimed_at    INT UNSIGNED    DEFAULT NULL,
	PRIMARY KEY (id),
	KEY idx_uploader (uploader_id),
	KEY idx_orphan   (is_orphan, created_at),
	KEY idx_parent   (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE phpbb_storage_quotas (
	user_id    INT UNSIGNED    NOT NULL,
	forum_id   INT UNSIGNED    NOT NULL DEFAULT 0,
	used_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
	max_bytes  BIGINT UNSIGNED NOT NULL,
	updated_at INT UNSIGNED    NOT NULL,
	PRIMARY KEY (user_id, forum_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

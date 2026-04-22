-- This file is part of the phpBB4 "Meridian" package.
-- @copyright (c) Irek Kubicki <phpbb@codebuilders.pl>
-- @license GNU General Public License, version 2 (GPL-2.0)

ALTER TABLE phpbb_users
	ADD COLUMN token_generation INT UNSIGNED NOT NULL DEFAULT 0,
	ADD COLUMN perm_version INT UNSIGNED NOT NULL DEFAULT 0;

CREATE TABLE phpbb_auth_refresh_tokens (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	user_id INT UNSIGNED NOT NULL,
	family_id CHAR(36) NOT NULL,
	token_hash CHAR(64) NOT NULL,
	issued_at INT UNSIGNED NOT NULL,
	expires_at INT UNSIGNED NOT NULL,
	revoked_at INT UNSIGNED NULL DEFAULT NULL,
	PRIMARY KEY (id),
	UNIQUE KEY uq_token_hash (token_hash),
	KEY idx_family (family_id),
	KEY idx_user (user_id),
	KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

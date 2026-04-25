<?php

/**
 *
 * This file is part of the phpBB4 "Meridian" package.
 *
 * @copyright (c) Irek Kubicki <phpbb@codebuilders.pl>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

declare(strict_types=1);

namespace phpbb\db\migrations;

use Doctrine\DBAL\Connection;

/**
 * Migration: Create Messaging Service Schema (M7)
 *
 * Creates 5 messaging tables:
 * - messaging_conversations
 * - messaging_messages
 * - messaging_participants
 * - messaging_message_deletes
 *
 * @TAG database_migration
 */
class Version20260424MessageSchema
{
	private const TABLE_PREFIX = 'phpbb_';

	public function __construct(
		private readonly Connection $connection,
	) {
	}

	public function up(): void
	{
		$this->createConversationsTable();
		$this->createMessagesTable();
		$this->createParticipantsTable();
		$this->createMessageDeletesTable();
	}

	public function down(): void
	{
		$this->connection->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE_PREFIX . 'messaging_message_deletes');
		$this->connection->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE_PREFIX . 'messaging_participants');
		$this->connection->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE_PREFIX . 'messaging_messages');
		$this->connection->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE_PREFIX . 'messaging_conversations');
	}

	private function createConversationsTable(): void
	{
		$sql = '
			CREATE TABLE IF NOT EXISTS ' . self::TABLE_PREFIX . 'messaging_conversations (
				conversation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				participant_hash CHAR(64) NOT NULL COMMENT "SHA-256 of sorted participant IDs",
				title VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
				created_by INT UNSIGNED NOT NULL,
				created_at INT UNSIGNED NOT NULL,
				last_message_id BIGINT UNSIGNED DEFAULT NULL,
				last_message_at INT UNSIGNED DEFAULT NULL,
				message_count INT UNSIGNED NOT NULL DEFAULT 0,
				participant_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				
				PRIMARY KEY (conversation_id),
				UNIQUE KEY uidx_participant_hash (participant_hash),
				KEY idx_last_message_at (last_message_at DESC),
				KEY idx_created_by (created_by),
				
				CONSTRAINT fk_conversations_created_by FOREIGN KEY (created_by) 
					REFERENCES ' . self::TABLE_PREFIX . 'users(user_id) ON DELETE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT="Conversations (thread-per-participant-set model)"
		';
		$this->connection->executeStatement($sql);
	}

	private function createMessagesTable(): void
	{
		$sql = '
			CREATE TABLE IF NOT EXISTS ' . self::TABLE_PREFIX . 'messaging_messages (
				message_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				conversation_id BIGINT UNSIGNED NOT NULL,
				author_id INT UNSIGNED NOT NULL,
				message_text MEDIUMTEXT NOT NULL COLLATE utf8mb4_unicode_ci,
				message_subject VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
				created_at INT UNSIGNED NOT NULL,
				edited_at INT UNSIGNED DEFAULT NULL,
				edit_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				metadata JSON DEFAULT NULL COMMENT "Extensible: attachments, etc.",
				
				PRIMARY KEY (message_id),
				KEY idx_conversation_time (conversation_id, created_at),
				KEY idx_conversation_id (conversation_id, message_id),
				KEY idx_author (author_id),
				
				CONSTRAINT fk_messages_conversation FOREIGN KEY (conversation_id) 
					REFERENCES ' . self::TABLE_PREFIX . 'messaging_conversations(conversation_id) ON DELETE CASCADE,
				CONSTRAINT fk_messages_author FOREIGN KEY (author_id) 
					REFERENCES ' . self::TABLE_PREFIX . 'users(user_id) ON DELETE SET NULL
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT="Messages (no BBCode/formatting here, plugins handle rendering)"
		';
		$this->connection->executeStatement($sql);
	}

	private function createParticipantsTable(): void
	{
		$sql = '
			CREATE TABLE IF NOT EXISTS ' . self::TABLE_PREFIX . 'messaging_participants (
				conversation_id BIGINT UNSIGNED NOT NULL,
				user_id INT UNSIGNED NOT NULL,
				role ENUM("owner", "member", "hidden") NOT NULL DEFAULT "member",
				state ENUM("active", "pinned", "archived") NOT NULL DEFAULT "active",
				joined_at INT UNSIGNED NOT NULL,
				left_at INT UNSIGNED DEFAULT NULL COMMENT "NULL = still active",
				last_read_message_id BIGINT UNSIGNED DEFAULT NULL,
				last_read_at INT UNSIGNED DEFAULT NULL,
				is_muted TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
				is_blocked TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
				
				PRIMARY KEY (conversation_id, user_id),
				KEY idx_user_state (user_id, state, left_at),
				KEY idx_user_active (user_id, left_at),
				
				CONSTRAINT fk_participants_conversation FOREIGN KEY (conversation_id) 
					REFERENCES ' . self::TABLE_PREFIX . 'messaging_conversations(conversation_id) ON DELETE CASCADE,
				CONSTRAINT fk_participants_user FOREIGN KEY (user_id) 
					REFERENCES ' . self::TABLE_PREFIX . 'users(user_id) ON DELETE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT="Per-user conversation state (read cursors, roles, organization)"
		';
		$this->connection->executeStatement($sql);
	}

	private function createMessageDeletesTable(): void
	{
		$sql = '
			CREATE TABLE IF NOT EXISTS ' . self::TABLE_PREFIX . 'messaging_message_deletes (
				conversation_id BIGINT UNSIGNED NOT NULL,
				message_id BIGINT UNSIGNED NOT NULL,
				user_id INT UNSIGNED NOT NULL,
				deleted_at INT UNSIGNED NOT NULL,
				
				PRIMARY KEY (conversation_id, message_id, user_id),
				
				CONSTRAINT fk_msg_deletes_conversation FOREIGN KEY (conversation_id) 
					REFERENCES ' . self::TABLE_PREFIX . 'messaging_conversations(conversation_id) ON DELETE CASCADE,
				CONSTRAINT fk_msg_deletes_message FOREIGN KEY (message_id) 
					REFERENCES ' . self::TABLE_PREFIX . 'messaging_messages(message_id) ON DELETE CASCADE,
				CONSTRAINT fk_msg_deletes_user FOREIGN KEY (user_id) 
					REFERENCES ' . self::TABLE_PREFIX . 'users(user_id) ON DELETE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT="Per-participant soft-delete tracking (message visible again if undeleted)"
		';
		$this->connection->executeStatement($sql);
	}
}

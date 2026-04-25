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

namespace phpbb\tests\messaging\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use phpbb\messaging\Repository\DbalParticipantRepository;
use PHPUnit\Framework\TestCase;

class DbalParticipantRepositoryTest extends TestCase
{
	private Connection $connection;
	private DbalParticipantRepository $repository;

	protected function setUp(): void
	{
		$this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
		$this->setupDatabase();
		$this->repository = new DbalParticipantRepository($this->connection);
	}

	private function setupDatabase(): void
	{
		$this->connection->executeStatement('
			CREATE TABLE IF NOT EXISTS phpbb_messaging_participants (
				conversation_id INTEGER NOT NULL,
				user_id INTEGER NOT NULL,
				role TEXT NOT NULL DEFAULT "member",
				state TEXT NOT NULL DEFAULT "active",
				joined_at INTEGER NOT NULL,
				left_at INTEGER,
				last_read_message_id INTEGER,
				last_read_at INTEGER,
				is_muted INTEGER NOT NULL DEFAULT 0,
				is_blocked INTEGER NOT NULL DEFAULT 0,
				PRIMARY KEY (conversation_id, user_id)
			)
		');
	}

	public function testInsertAndFindByConversationAndUser(): void
	{
		$now = time();
		$this->repository->insert(conversationId: 1, userId: 2, role: 'member');

		$participant = $this->repository->findByConversationAndUser(conversationId: 1, userId: 2);
		self::assertNotNull($participant);
		self::assertSame(1, $participant->conversationId);
		self::assertSame(2, $participant->userId);
		self::assertSame('member', $participant->role);
		self::assertSame('active', $participant->state);
	}

	public function testFindByConversationAndUserReturnsNull(): void
	{
		$participant = $this->repository->findByConversationAndUser(conversationId: 1, userId: 999);
		self::assertNull($participant);
	}

	public function testFindByConversation(): void
	{
		$this->repository->insert(conversationId: 1, userId: 2);
		$this->repository->insert(conversationId: 1, userId: 3);
		$this->repository->insert(conversationId: 1, userId: 4);

		$participants = $this->repository->findByConversation(conversationId: 1);
		self::assertCount(3, $participants);
	}

	public function testFindByUser(): void
	{
		$this->repository->insert(conversationId: 1, userId: 2);
		$this->repository->insert(conversationId: 2, userId: 2);
		$this->repository->insert(conversationId: 3, userId: 2);

		$participants = $this->repository->findByUser(userId: 2);
		self::assertCount(3, $participants);
	}

	public function testUpdateParticipant(): void
	{
		$this->repository->insert(conversationId: 1, userId: 2, role: 'member');

		$this->repository->update(conversationId: 1, userId: 2, fields: [
			'role'     => 'owner',
			'state'    => 'pinned',
			'is_muted' => 1,
		]);

		$participant = $this->repository->findByConversationAndUser(conversationId: 1, userId: 2);
		self::assertSame('owner', $participant->role);
		self::assertSame('pinned', $participant->state);
		self::assertTrue($participant->isMuted);
	}

	public function testDeleteParticipant(): void
	{
		$this->repository->insert(conversationId: 1, userId: 2);

		$exists = $this->repository->findByConversationAndUser(conversationId: 1, userId: 2);
		self::assertNotNull($exists);

		$this->repository->delete(conversationId: 1, userId: 2);

		$deleted = $this->repository->findByConversationAndUser(conversationId: 1, userId: 2);
		self::assertNull($deleted);
	}

	public function testInsertWithOwnerRole(): void
	{
		$this->repository->insert(conversationId: 1, userId: 1, role: 'owner');

		$participant = $this->repository->findByConversationAndUser(conversationId: 1, userId: 1);
		self::assertSame('owner', $participant->role);
	}

	public function testParticipantFlagsMuted(): void
	{
		$this->repository->insert(conversationId: 1, userId: 2);
		$this->repository->update(conversationId: 1, userId: 2, fields: ['is_muted' => 1]);

		$participant = $this->repository->findByConversationAndUser(conversationId: 1, userId: 2);
		self::assertTrue($participant->isMuted);
	}

	public function testParticipantFlagsBlocked(): void
	{
		$this->repository->insert(conversationId: 1, userId: 2);
		$this->repository->update(conversationId: 1, userId: 2, fields: ['is_blocked' => 1]);

		$participant = $this->repository->findByConversationAndUser(conversationId: 1, userId: 2);
		self::assertTrue($participant->isBlocked);
	}

	public function testParticipantLastReadTracking(): void
	{
		$this->repository->insert(conversationId: 1, userId: 2);
		$now = time();

		$this->repository->update(conversationId: 1, userId: 2, fields: [
			'last_read_message_id' => 42,
			'last_read_at'         => $now,
		]);

		$participant = $this->repository->findByConversationAndUser(conversationId: 1, userId: 2);
		self::assertSame(42, $participant->lastReadMessageId);
		self::assertSame($now, $participant->lastReadAt);
	}
}

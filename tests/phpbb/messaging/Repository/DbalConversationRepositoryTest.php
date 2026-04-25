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
use phpbb\api\DTO\PaginationContext;
use phpbb\messaging\DTO\Request\CreateConversationRequest;
use phpbb\messaging\Repository\DbalConversationRepository;
use PHPUnit\Framework\TestCase;

class DbalConversationRepositoryTest extends TestCase
{
	private Connection $connection;
	private DbalConversationRepository $repository;

	protected function setUp(): void
	{
		$this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
		$this->setupDatabase();
		$this->repository = new DbalConversationRepository($this->connection);
	}

	private function setupDatabase(): void
	{
		// Create conversations table
		$this->connection->executeStatement('
			CREATE TABLE IF NOT EXISTS phpbb_messaging_conversations (
				conversation_id INTEGER PRIMARY KEY AUTOINCREMENT,
				participant_hash TEXT NOT NULL UNIQUE,
				title TEXT,
				created_by INTEGER NOT NULL,
				created_at INTEGER NOT NULL,
				last_message_id INTEGER,
				last_message_at INTEGER,
				message_count INTEGER NOT NULL DEFAULT 0,
				participant_count INTEGER NOT NULL DEFAULT 0
			)
		');

		// Create participants table
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

	public function testInsertAndFindById(): void
	{
		$request = new CreateConversationRequest(
			title: 'Test Conversation',
			participantIds: [2, 3],
		);

		$id = $this->repository->insert($request, createdBy: 1);
		self::assertGreaterThan(0, $id);

		$conversation = $this->repository->findById($id);
		self::assertNotNull($conversation);
		self::assertSame('Test Conversation', $conversation->title);
		self::assertSame(1, $conversation->createdBy);
		self::assertSame(0, $conversation->messageCount);
	}

	public function testFindByIdReturnsNullForNonexistent(): void
	{
		$conversation = $this->repository->findById(999);
		self::assertNull($conversation);
	}

	public function testFindByParticipantHash(): void
	{
		$request = new CreateConversationRequest(
			title: 'Hash Test',
			participantIds: [2, 3],
		);

		$this->repository->insert($request, createdBy: 1);

		// The hash should be sha256 of sorted: 1,2,3
		$participantIds = [1, 2, 3];
		sort($participantIds);
		$hash = hash('sha256', implode(',', $participantIds));

		$conversation = $this->repository->findByParticipantHash($hash);
		self::assertNotNull($conversation);
		self::assertSame('Hash Test', $conversation->title);
	}

	public function testUpdateConversation(): void
	{
		$request = new CreateConversationRequest(
			title: 'Original Title',
			participantIds: [2],
		);

		$id = $this->repository->insert($request, createdBy: 1);

		$this->repository->update($id, [
			'title'        => 'Updated Title',
			'message_count' => 5,
		]);

		$conversation = $this->repository->findById($id);
		self::assertSame('Updated Title', $conversation->title);
		self::assertSame(5, $conversation->messageCount);
	}

	public function testDeleteConversation(): void
	{
		$request = new CreateConversationRequest(
			title: 'To Delete',
			participantIds: [2],
		);

		$id = $this->repository->insert($request, createdBy: 1);
		$this->repository->delete($id);

		$conversation = $this->repository->findById($id);
		self::assertNull($conversation);
	}

	public function testListByUserWithoutState(): void
	{
		// Create conversations
		$request1 = new CreateConversationRequest(title: 'Conv 1', participantIds: [2]);
		$request2 = new CreateConversationRequest(title: 'Conv 2', participantIds: [3]);

		$id1 = $this->repository->insert($request1, createdBy: 1);
		$id2 = $this->repository->insert($request2, createdBy: 1);

		// Add user 1 as participant
		$now = time();
		$this->connection->executeStatement(
			'INSERT INTO phpbb_messaging_participants (conversation_id, user_id, joined_at) VALUES (?, ?, ?)',
			[$id1, 1, $now],
		);
		$this->connection->executeStatement(
			'INSERT INTO phpbb_messaging_participants (conversation_id, user_id, joined_at) VALUES (?, ?, ?)',
			[$id2, 1, $now],
		);

		$ctx = new PaginationContext(page: 1, perPage: 10);
		$result = $this->repository->listByUser(userId: 1, state: null, ctx: $ctx);

		self::assertSame(2, $result->total);
		self::assertCount(2, $result->items);
	}

	public function testListByUserWithStateFilter(): void
	{
		$request = new CreateConversationRequest(title: 'Conv', participantIds: [2]);
		$id = $this->repository->insert($request, createdBy: 1);

		$now = time();
		$this->connection->executeStatement(
			'INSERT INTO phpbb_messaging_participants (conversation_id, user_id, state, joined_at) VALUES (?, ?, ?, ?)',
			[$id, 1, 'pinned', $now],
		);

		$ctx = new PaginationContext(page: 1, perPage: 10);
		$result = $this->repository->listByUser(userId: 1, state: 'pinned', ctx: $ctx);

		self::assertSame(1, $result->total);

		$result = $this->repository->listByUser(userId: 1, state: 'archived', ctx: $ctx);
		self::assertSame(0, $result->total);
	}

	public function testListByUserExcludesLeftParticipants(): void
	{
		$request = new CreateConversationRequest(title: 'Conv', participantIds: [2]);
		$id = $this->repository->insert($request, createdBy: 1);

		$now = time();
		$this->connection->executeStatement(
			'INSERT INTO phpbb_messaging_participants (conversation_id, user_id, joined_at, left_at) VALUES (?, ?, ?, ?)',
			[$id, 1, $now, $now + 100],
		);

		$ctx = new PaginationContext(page: 1, perPage: 10);
		$result = $this->repository->listByUser(userId: 1, state: null, ctx: $ctx);

		self::assertSame(0, $result->total);
	}
}

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
use phpbb\messaging\DTO\Request\SendMessageRequest;
use phpbb\messaging\Repository\DbalMessageRepository;
use PHPUnit\Framework\TestCase;

class DbalMessageRepositoryTest extends TestCase
{
	private Connection $connection;
	private DbalMessageRepository $repository;

	protected function setUp(): void
	{
		$this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
		$this->setupDatabase();
		$this->repository = new DbalMessageRepository($this->connection);
	}

	private function setupDatabase(): void
	{
		$this->connection->executeStatement('
			CREATE TABLE IF NOT EXISTS phpbb_messaging_messages (
				message_id INTEGER PRIMARY KEY AUTOINCREMENT,
				conversation_id INTEGER NOT NULL,
				author_id INTEGER NOT NULL,
				message_text TEXT NOT NULL,
				message_subject TEXT,
				created_at INTEGER NOT NULL,
				edited_at INTEGER,
				edit_count INTEGER NOT NULL DEFAULT 0,
				metadata TEXT
			)
		');

		$this->connection->executeStatement('
			CREATE TABLE IF NOT EXISTS phpbb_messaging_message_deletes (
				conversation_id INTEGER NOT NULL,
				message_id INTEGER NOT NULL,
				user_id INTEGER NOT NULL,
				deleted_at INTEGER NOT NULL,
				PRIMARY KEY (conversation_id, message_id, user_id)
			)
		');
	}

	public function testInsertAndFindById(): void
	{
		$request = new SendMessageRequest(
			messageText: 'Hello, World!',
			messageSubject: 'Greeting',
			metadata: null,
		);

		$id = $this->repository->insert(conversationId: 1, request: $request, authorId: 2);
		self::assertGreaterThan(0, $id);

		$message = $this->repository->findById($id);
		self::assertNotNull($message);
		self::assertSame('Hello, World!', $message->messageText);
		self::assertSame('Greeting', $message->messageSubject);
		self::assertSame(2, $message->authorId);
	}

	public function testFindByIdReturnsNull(): void
	{
		$message = $this->repository->findById(999);
		self::assertNull($message);
	}

	public function testUpdateMessage(): void
	{
		$request = new SendMessageRequest(messageText: 'Original');
		$id = $this->repository->insert(conversationId: 1, request: $request, authorId: 2);

		$now = time();
		$this->repository->update($id, [
			'message_text' => 'Updated text',
			'edited_at'    => $now,
			'edit_count'   => 1,
		]);

		$message = $this->repository->findById($id);
		self::assertSame('Updated text', $message->messageText);
		self::assertSame(1, $message->editCount);
	}

	public function testListByConversation(): void
	{
		$req1 = new SendMessageRequest(messageText: 'Message 1');
		$req2 = new SendMessageRequest(messageText: 'Message 2');

		$this->repository->insert(conversationId: 1, request: $req1, authorId: 2);
		$this->repository->insert(conversationId: 1, request: $req2, authorId: 3);

		$ctx = new PaginationContext(page: 1, perPage: 10);
		$result = $this->repository->listByConversation(conversationId: 1, ctx: $ctx);

		self::assertSame(2, $result->total);
		self::assertCount(2, $result->items);
	}

	public function testListByConversationPagination(): void
	{
		for ($i = 1; $i <= 15; $i++) {
			$request = new SendMessageRequest(messageText: "Message $i");
			$this->repository->insert(conversationId: 1, request: $request, authorId: 2);
		}

		$ctx = new PaginationContext(page: 1, perPage: 10);
		$result1 = $this->repository->listByConversation(conversationId: 1, ctx: $ctx);
		self::assertSame(15, $result1->total);
		self::assertCount(10, $result1->items);

		$ctx = new PaginationContext(page: 2, perPage: 10);
		$result2 = $this->repository->listByConversation(conversationId: 1, ctx: $ctx);
		self::assertCount(5, $result2->items);
	}

	public function testSearchMessages(): void
	{
		$req1 = new SendMessageRequest(messageText: 'Hello World');
		$req2 = new SendMessageRequest(messageText: 'Goodbye');
		$req3 = new SendMessageRequest(messageText: 'Hello Again');

		$this->repository->insert(conversationId: 1, request: $req1, authorId: 2);
		$this->repository->insert(conversationId: 1, request: $req2, authorId: 2);
		$this->repository->insert(conversationId: 1, request: $req3, authorId: 2);

		$ctx = new PaginationContext(page: 1, perPage: 10);
		$result = $this->repository->search(conversationId: 1, query: 'Hello', ctx: $ctx);

		self::assertSame(2, $result->total);
		self::assertCount(2, $result->items);
	}

	public function testSoftDeletePerUser(): void
	{
		$request = new SendMessageRequest(messageText: 'Message');
		$messageId = $this->repository->insert(conversationId: 1, request: $request, authorId: 2);

		$this->repository->deletePerUser(messageId: $messageId, userId: 3);

		$isDeleted = $this->repository->isDeletedForUser(messageId: $messageId, userId: 3);
		self::assertTrue($isDeleted);

		$isDeleted = $this->repository->isDeletedForUser(messageId: $messageId, userId: 4);
		self::assertFalse($isDeleted);
	}

	public function testIsDeletedForUserFalse(): void
	{
		$request = new SendMessageRequest(messageText: 'Message');
		$messageId = $this->repository->insert(conversationId: 1, request: $request, authorId: 2);

		$isDeleted = $this->repository->isDeletedForUser(messageId: $messageId, userId: 3);
		self::assertFalse($isDeleted);
	}
}

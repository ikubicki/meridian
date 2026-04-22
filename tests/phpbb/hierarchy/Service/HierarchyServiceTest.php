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

namespace phpbb\Tests\hierarchy\Service;

use phpbb\common\Event\DomainEventCollection;
use phpbb\hierarchy\DTO\CreateForumRequest;
use phpbb\hierarchy\DTO\UpdateForumRequest;
use phpbb\hierarchy\Entity\ForumType;
use phpbb\hierarchy\Event\ForumCreatedEvent;
use phpbb\hierarchy\Event\ForumDeletedEvent;
use phpbb\hierarchy\Event\ForumMovedEvent;
use phpbb\hierarchy\Event\ForumUpdatedEvent;
use phpbb\hierarchy\Repository\DbalForumRepository;
use phpbb\hierarchy\Service\HierarchyService;
use phpbb\hierarchy\Service\TreeService;
use phpbb\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;

class HierarchyServiceTest extends IntegrationTestCase
{
	private HierarchyService $service;
	private DbalForumRepository $repo;

	protected function setUpSchema(): void
	{
		$this->connection->executeStatement('
			CREATE TABLE phpbb_forums (
				forum_id               INTEGER PRIMARY KEY AUTOINCREMENT,
				parent_id              INTEGER NOT NULL DEFAULT 0,
				left_id                INTEGER NOT NULL DEFAULT 0,
				right_id               INTEGER NOT NULL DEFAULT 0,
				forum_parents          TEXT    NOT NULL DEFAULT \'\',
				forum_name             TEXT    NOT NULL DEFAULT \'\',
				forum_desc             TEXT    NOT NULL DEFAULT \'\',
				forum_desc_bitfield    TEXT    NOT NULL DEFAULT \'\',
				forum_desc_options     INTEGER NOT NULL DEFAULT 7,
				forum_desc_uid         TEXT    NOT NULL DEFAULT \'\',
				forum_link             TEXT    NOT NULL DEFAULT \'\',
				forum_password         TEXT    NOT NULL DEFAULT \'\',
				forum_style            INTEGER NOT NULL DEFAULT 0,
				forum_image            TEXT    NOT NULL DEFAULT \'\',
				forum_rules            TEXT    NOT NULL DEFAULT \'\',
				forum_rules_link       TEXT    NOT NULL DEFAULT \'\',
				forum_rules_bitfield   TEXT    NOT NULL DEFAULT \'\',
				forum_rules_options    INTEGER NOT NULL DEFAULT 7,
				forum_rules_uid        TEXT    NOT NULL DEFAULT \'\',
				forum_topics_per_page  INTEGER NOT NULL DEFAULT 0,
				forum_type             INTEGER NOT NULL DEFAULT 1,
				forum_status           INTEGER NOT NULL DEFAULT 0,
				forum_posts_approved     INTEGER NOT NULL DEFAULT 0,
				forum_posts_unapproved   INTEGER NOT NULL DEFAULT 0,
				forum_posts_softdeleted  INTEGER NOT NULL DEFAULT 0,
				forum_topics_approved    INTEGER NOT NULL DEFAULT 0,
				forum_topics_unapproved  INTEGER NOT NULL DEFAULT 0,
				forum_topics_softdeleted INTEGER NOT NULL DEFAULT 0,
				forum_last_post_id       INTEGER NOT NULL DEFAULT 0,
				forum_last_poster_id     INTEGER NOT NULL DEFAULT 0,
				forum_last_post_subject  TEXT    NOT NULL DEFAULT \'\',
				forum_last_post_time     INTEGER NOT NULL DEFAULT 0,
				forum_last_poster_name   TEXT    NOT NULL DEFAULT \'\',
				forum_last_poster_colour TEXT    NOT NULL DEFAULT \'\',
				forum_flags              INTEGER NOT NULL DEFAULT 32,
				forum_options            INTEGER NOT NULL DEFAULT 0,
				display_on_index         INTEGER NOT NULL DEFAULT 1,
				display_subforum_list    INTEGER NOT NULL DEFAULT 1,
				enable_indexing          INTEGER NOT NULL DEFAULT 1,
				enable_icons             INTEGER NOT NULL DEFAULT 0,
				prune_next               INTEGER NOT NULL DEFAULT 0,
				prune_days               INTEGER NOT NULL DEFAULT 0,
				prune_viewed             INTEGER NOT NULL DEFAULT 0,
				prune_freq               INTEGER NOT NULL DEFAULT 0,
				enable_prune             INTEGER NOT NULL DEFAULT 0
			)
		');

		$this->repo    = new DbalForumRepository($this->connection);
		$treeService   = new TreeService($this->repo);
		$this->service = new HierarchyService($this->repo, $treeService);
	}

	private function insertForum(array $overrides = []): int
	{
		$defaults = [
			'forum_name'    => 'Test',
			'forum_type'    => 1,
			'parent_id'     => 0,
			'left_id'       => 0,
			'right_id'      => 0,
			'forum_parents' => '',
		];

		$data         = array_merge($defaults, $overrides);
		$columns      = implode(', ', array_keys($data));
		$placeholders = implode(', ', array_map(static fn (string $k) => ':' . $k, array_keys($data)));

		$this->connection->executeStatement(
			'INSERT INTO phpbb_forums (' . $columns . ') VALUES (' . $placeholders . ')',
			$data,
		);

		return (int) $this->connection->lastInsertId();
	}

	#[Test]
	public function testListForums_nullParent_returnsRootForums(): void
	{
		// Arrange — create 2 root forums and 1 child (should not appear in list)
		$request1 = new CreateForumRequest(name: 'Root A', type: ForumType::Forum, parentId: 0, actorId: 1);
		$request2 = new CreateForumRequest(name: 'Root B', type: ForumType::Forum, parentId: 0, actorId: 1);
		$events1  = $this->service->createForum($request1);
		$this->service->createForum($request2);

		$rootId    = $events1->first()->entityId;
		$childReq  = new CreateForumRequest(name: 'Child', type: ForumType::Forum, parentId: $rootId, actorId: 1);
		$this->service->createForum($childReq);

		// Act
		$result = $this->service->listForums(null);

		// Assert
		$this->assertCount(2, $result);
	}

	#[Test]
	public function testListForums_withParentId_returnsChildren(): void
	{
		// Arrange — create 1 root and 2 children under it
		$rootReq = new CreateForumRequest(name: 'Root', type: ForumType::Forum, parentId: 0, actorId: 1);
		$events  = $this->service->createForum($rootReq);
		$rootId  = $events->first()->entityId;

		$this->service->createForum(new CreateForumRequest(name: 'Child 1', type: ForumType::Forum, parentId: $rootId, actorId: 1));
		$this->service->createForum(new CreateForumRequest(name: 'Child 2', type: ForumType::Forum, parentId: $rootId, actorId: 1));

		// Act
		$result = $this->service->listForums($rootId);

		// Assert
		$this->assertCount(2, $result);
	}

	#[Test]
	public function testGetForum_returnsCorrectDto(): void
	{
		// Arrange
		$request = new CreateForumRequest(name: 'My Forum', type: ForumType::Forum, parentId: 0, actorId: 1);
		$events  = $this->service->createForum($request);
		$forumId = $events->first()->entityId;

		// Act
		$dto = $this->service->getForum($forumId);

		// Assert
		$this->assertSame('My Forum', $dto->name);
		$this->assertSame($forumId, $dto->id);
	}

	#[Test]
	public function testGetForum_notFound_throwsInvalidArgument(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		$this->service->getForum(9999);
	}

	#[Test]
	public function testCreateForum_returnsEventCollection_withForumCreatedEvent(): void
	{
		// Arrange
		$request = new CreateForumRequest(name: 'New Forum', type: ForumType::Forum, parentId: 0, actorId: 42);

		// Act
		$events = $this->service->createForum($request);

		// Assert
		$this->assertInstanceOf(DomainEventCollection::class, $events);
		$this->assertInstanceOf(ForumCreatedEvent::class, $events->first());
		$this->assertSame(42, $events->first()->actorId);
	}

	#[Test]
	public function testCreateForum_persistsForum(): void
	{
		// Arrange
		$request = new CreateForumRequest(name: 'Persisted Forum', type: ForumType::Forum, parentId: 0, actorId: 1);

		// Act
		$events  = $this->service->createForum($request);
		$forumId = $events->first()->entityId;
		$dto     = $this->service->getForum($forumId);

		// Assert
		$this->assertSame('Persisted Forum', $dto->name);
	}

	#[Test]
	public function testUpdateForum_returnsEventCollection_withForumUpdatedEvent(): void
	{
		// Arrange
		$createEvents = $this->service->createForum(
			new CreateForumRequest(name: 'Original', type: ForumType::Forum, parentId: 0, actorId: 1)
		);
		$forumId = $createEvents->first()->entityId;

		$updateRequest = new UpdateForumRequest(forumId: $forumId, actorId: 7, name: 'Updated');

		// Act
		$events = $this->service->updateForum($updateRequest);

		// Assert
		$this->assertInstanceOf(DomainEventCollection::class, $events);
		$this->assertInstanceOf(ForumUpdatedEvent::class, $events->first());
		$this->assertSame($forumId, $events->first()->entityId);
		$this->assertSame(7, $events->first()->actorId);
	}

	#[Test]
	public function testDeleteForum_removesFromRepo(): void
	{
		// Arrange
		$createEvents = $this->service->createForum(
			new CreateForumRequest(name: 'ToDelete', type: ForumType::Forum, parentId: 0, actorId: 1)
		);
		$forumId = $createEvents->first()->entityId;

		// Act
		$deleteEvents = $this->service->deleteForum($forumId, 1);

		// Assert — event is ForumDeletedEvent
		$this->assertInstanceOf(ForumDeletedEvent::class, $deleteEvents->first());

		// Assert — forum is gone
		$this->expectException(\InvalidArgumentException::class);
		$this->service->getForum($forumId);
	}

	#[Test]
	public function testDeleteForum_withChildren_throwsInvalidArgument(): void
	{
		// Arrange — create parent + child
		$parentEvents = $this->service->createForum(
			new CreateForumRequest(name: 'Parent', type: ForumType::Forum, parentId: 0, actorId: 1)
		);
		$parentId = $parentEvents->first()->entityId;

		$this->service->createForum(
			new CreateForumRequest(name: 'Child', type: ForumType::Forum, parentId: $parentId, actorId: 1)
		);

		// Act & Assert
		$this->expectException(\InvalidArgumentException::class);
		$this->service->deleteForum($parentId, 1);
	}

	#[Test]
	public function testMoveForum_updatesParentId(): void
	{
		// Arrange — create root1, root2, and a child under root1
		$root1Events = $this->service->createForum(
			new CreateForumRequest(name: 'Root 1', type: ForumType::Forum, parentId: 0, actorId: 1)
		);
		$root1Id = $root1Events->first()->entityId;

		$root2Events = $this->service->createForum(
			new CreateForumRequest(name: 'Root 2', type: ForumType::Forum, parentId: 0, actorId: 1)
		);
		$root2Id = $root2Events->first()->entityId;

		$childEvents = $this->service->createForum(
			new CreateForumRequest(name: 'Child', type: ForumType::Forum, parentId: $root1Id, actorId: 1)
		);
		$childId = $childEvents->first()->entityId;

		// Act — move child from root1 to root2
		$moveEvents = $this->service->moveForum($childId, $root2Id, 1);

		// Assert — event is ForumMovedEvent
		$this->assertInstanceOf(ForumMovedEvent::class, $moveEvents->first());

		// Assert — parent_id was updated
		$moved = $this->repo->findById($childId);
		$this->assertSame($root2Id, $moved->parentId);
	}
}

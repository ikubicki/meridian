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

namespace phpbb\Tests\hierarchy\Repository;

use phpbb\hierarchy\DTO\CreateForumRequest;
use phpbb\hierarchy\DTO\UpdateForumRequest;
use phpbb\hierarchy\Entity\Forum;
use phpbb\hierarchy\Entity\ForumType;
use phpbb\hierarchy\Repository\DbalForumRepository;
use phpbb\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;

class DbalForumRepositoryTest extends IntegrationTestCase
{
	private DbalForumRepository $repository;

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

		$this->repository = new DbalForumRepository($this->connection);
	}

	private function insertForum(array $overrides = []): int
	{
		$defaults = [
			'forum_name'   => 'Test',
			'forum_type'   => 1,
			'parent_id'    => 0,
			'left_id'      => 0,
			'right_id'     => 0,
			'forum_parents' => '',
		];

		$data    = array_merge($defaults, $overrides);
		$columns = implode(', ', array_keys($data));
		$placeholders = implode(', ', array_map(static fn (string $k) => ':' . $k, array_keys($data)));

		$this->connection->executeStatement(
			'INSERT INTO phpbb_forums (' . $columns . ') VALUES (' . $placeholders . ')',
			$data,
		);

		return (int) $this->connection->lastInsertId();
	}

	#[Test]
	public function testFindById_found_returnsHydratedForum(): void
	{
		// Arrange
		$id = $this->insertForum(['forum_name' => 'Support', 'forum_type' => 1]);

		// Act
		$forum = $this->repository->findById($id);

		// Assert
		$this->assertInstanceOf(Forum::class, $forum);
		$this->assertSame('Support', $forum->name);
		$this->assertSame(ForumType::Forum, $forum->type);
	}

	#[Test]
	public function testFindById_notFound_returnsNull(): void
	{
		// Act
		$result = $this->repository->findById(9999);

		// Assert
		$this->assertNull($result);
	}

	#[Test]
	public function testFindAll_returnsAllForumsOrderedByLeftId(): void
	{
		// Arrange
		$id1 = $this->insertForum(['forum_name' => 'Forum A', 'left_id' => 5]);
		$id2 = $this->insertForum(['forum_name' => 'Forum B', 'left_id' => 1]);
		$id3 = $this->insertForum(['forum_name' => 'Forum C', 'left_id' => 3]);

		// Act
		$result = $this->repository->findAll();

		// Assert
		$this->assertCount(3, $result);
		$ids = array_keys($result);
		$this->assertSame($id2, $ids[0]);
		$this->assertSame($id3, $ids[1]);
		$this->assertSame($id1, $ids[2]);
		$this->assertSame(1, $result[$id2]->leftId);
		$this->assertSame(3, $result[$id3]->leftId);
		$this->assertSame(5, $result[$id1]->leftId);
	}

	#[Test]
	public function testFindChildren_returnsDirectChildrenOnly(): void
	{
		// Arrange
		$parentId = $this->insertForum(['forum_name' => 'Parent']);
		$child1Id = $this->insertForum(['forum_name' => 'Child 1', 'parent_id' => $parentId]);
		$child2Id = $this->insertForum(['forum_name' => 'Child 2', 'parent_id' => $parentId]);
		$this->insertForum(['forum_name' => 'Grandchild', 'parent_id' => $child1Id]);

		// Act
		$children = $this->repository->findChildren($parentId);

		// Assert
		$this->assertCount(2, $children);
		$this->assertArrayHasKey($child1Id, $children);
		$this->assertArrayHasKey($child2Id, $children);
	}

	#[Test]
	public function testFindChildren_emptyResult_returnsEmptyArray(): void
	{
		// Act
		$result = $this->repository->findChildren(999);

		// Assert
		$this->assertSame([], $result);
	}

	#[Test]
	public function testInsertRaw_persistsAllFields(): void
	{
		// Arrange
		$request = new CreateForumRequest(
			name: 'New Forum',
			type: ForumType::Category,
			description: 'A description',
		);

		// Act
		$id = $this->repository->insertRaw($request);
		$forum = $this->repository->findById($id);

		// Assert
		$this->assertGreaterThan(0, $id);
		$this->assertInstanceOf(Forum::class, $forum);
		$this->assertSame('New Forum', $forum->name);
		$this->assertSame(ForumType::Category, $forum->type);
		$this->assertSame('A description', $forum->description);
	}

	#[Test]
	public function testInsertRaw_setsTreePositionToZero(): void
	{
		// Arrange
		$request = new CreateForumRequest(name: 'Tree Forum', type: ForumType::Forum);

		// Act
		$id    = $this->repository->insertRaw($request);
		$forum = $this->repository->findById($id);

		// Assert
		$this->assertSame(0, $forum->leftId);
		$this->assertSame(0, $forum->rightId);
	}

	#[Test]
	public function testInsertRaw_forumParentsInitialValueIsJsonArray(): void
	{
		// Arrange
		$request = new CreateForumRequest(name: 'Parents Forum', type: ForumType::Forum);

		// Act
		$id = $this->repository->insertRaw($request);

		$raw = $this->connection->executeQuery(
			'SELECT forum_parents FROM phpbb_forums WHERE forum_id = :id',
			['id' => $id],
		)->fetchOne();

		// Assert
		$this->assertSame('[]', $raw);
	}

	#[Test]
	public function testUpdate_changesOnlyNonNullFields(): void
	{
		// Arrange
		$request = new CreateForumRequest(
			name: 'Original',
			type: ForumType::Forum,
			description: 'Original desc',
		);
		$id = $this->repository->insertRaw($request);

		// Act
		$updated = $this->repository->update(new UpdateForumRequest(
			forumId: $id,
			name: 'Updated',
		));

		// Assert
		$this->assertSame('Updated', $updated->name);
		$this->assertSame('Original desc', $updated->description);
	}

	#[Test]
	public function testDelete_removesRow(): void
	{
		// Arrange
		$id = $this->repository->insertRaw(new CreateForumRequest(name: 'To Delete', type: ForumType::Forum));

		// Act
		$this->repository->delete($id);

		// Assert
		$this->assertNull($this->repository->findById($id));
	}

	#[Test]
	public function testShiftLeftIds_shiftsCorrectRows(): void
	{
		// Arrange
		$id1 = $this->insertForum(['forum_name' => 'F1', 'left_id' => 1]);
		$id3 = $this->insertForum(['forum_name' => 'F3', 'left_id' => 3]);
		$id5 = $this->insertForum(['forum_name' => 'F5', 'left_id' => 5]);

		// Act
		$this->repository->shiftLeftIds(3, 2);

		// Assert
		$this->assertSame(1, $this->repository->findById($id1)->leftId);
		$this->assertSame(5, $this->repository->findById($id3)->leftId);
		$this->assertSame(7, $this->repository->findById($id5)->leftId);
	}

	#[Test]
	public function testDecodeParents_jsonFormat_parsesCorrectly(): void
	{
		// Arrange
		$id = $this->insertForum(['forum_parents' => '{"0":"Root"}']);

		// Act
		$forum = $this->repository->findById($id);

		// Assert
		$this->assertNotEmpty($forum->parents);
		$this->assertSame('Root', $forum->parents['0']);
	}
}

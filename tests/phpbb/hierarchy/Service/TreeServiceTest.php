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

use phpbb\hierarchy\Repository\DbalForumRepository;
use phpbb\hierarchy\Service\TreeService;
use phpbb\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;

class TreeServiceTest extends IntegrationTestCase
{
	private DbalForumRepository $dbRepo;
	private TreeService $treeService;

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

		$this->dbRepo     = new DbalForumRepository($this->connection);
		$this->treeService = new TreeService($this->dbRepo);
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
	public function testInsertNode_firstRootNode_setsLeftRight_1_2(): void
	{
		// Arrange
		$forumId = $this->insertForum(['forum_name' => 'First Root']);

		// Act
		[$left, $right] = $this->treeService->insertNode($forumId, 0);

		// Assert
		$this->assertSame(1, $left);
		$this->assertSame(2, $right);

		$forum = $this->dbRepo->findById($forumId);
		$this->assertNotNull($forum);
		$this->assertSame(1, $forum->leftId);
		$this->assertSame(2, $forum->rightId);
	}

	#[Test]
	public function testInsertNode_secondRootNode_setsLeftRight_3_4(): void
	{
		// Arrange
		$forum1Id = $this->insertForum(['forum_name' => 'Root 1']);
		$this->treeService->insertNode($forum1Id, 0);

		$forum2Id = $this->insertForum(['forum_name' => 'Root 2']);

		// Act
		[$left, $right] = $this->treeService->insertNode($forum2Id, 0);

		// Assert
		$this->assertSame(3, $left);
		$this->assertSame(4, $right);

		$forum2 = $this->dbRepo->findById($forum2Id);
		$this->assertNotNull($forum2);
		$this->assertSame(3, $forum2->leftId);
		$this->assertSame(4, $forum2->rightId);
	}

	#[Test]
	public function testInsertNode_childNode_expandsParent(): void
	{
		// Arrange
		$parentId = $this->insertForum(['forum_name' => 'Parent']);
		$this->treeService->insertNode($parentId, 0);
		// parent: left=1, right=2

		$childId = $this->insertForum(['forum_name' => 'Child']);

		// Act
		[$childLeft, $childRight] = $this->treeService->insertNode($childId, $parentId);

		// Assert
		$this->assertSame(2, $childLeft);
		$this->assertSame(3, $childRight);

		$parent = $this->dbRepo->findById($parentId);
		$this->assertNotNull($parent);
		$this->assertSame(1, $parent->leftId);
		$this->assertSame(4, $parent->rightId);
	}

	#[Test]
	public function testRemoveNode_closesGap(): void
	{
		// Arrange: insert 3 root nodes at positions 1-2, 3-4, 5-6
		$forum1Id = $this->insertForum(['forum_name' => 'Node 1']);
		$this->treeService->insertNode($forum1Id, 0);

		$forum2Id = $this->insertForum(['forum_name' => 'Node 2']);
		$this->treeService->insertNode($forum2Id, 0);

		$forum3Id = $this->insertForum(['forum_name' => 'Node 3']);
		$this->treeService->insertNode($forum3Id, 0);

		// Act: remove the 2nd node (left=3, right=4)
		$this->treeService->removeNode(3, 4);

		// Assert: 3rd node shifts from left=5,right=6 to left=3,right=4
		$forum3 = $this->dbRepo->findById($forum3Id);
		$this->assertNotNull($forum3);
		$this->assertSame(3, $forum3->leftId);
		$this->assertSame(4, $forum3->rightId);
	}

	#[Test]
	public function testRemoveNode_leafNode_shiftsOtherNodes(): void
	{
		// Arrange
		$parentId = $this->insertForum(['forum_name' => 'Parent']);
		$this->treeService->insertNode($parentId, 0);
		// parent: left=1, right=2

		$childId = $this->insertForum(['forum_name' => 'Child']);
		$this->treeService->insertNode($childId, $parentId);
		// child: left=2, right=3; parent: left=1, right=4

		$otherRootId = $this->insertForum(['forum_name' => 'Other Root']);
		$this->treeService->insertNode($otherRootId, 0);
		// otherRoot: left=5, right=6

		$child = $this->dbRepo->findById($childId);
		$this->assertNotNull($child);

		// Act: remove child (width=2), closing gap
		$this->treeService->removeNode($child->leftId, $child->rightId);

		// Assert: parent should shrink back, other root should shift left
		$parent    = $this->dbRepo->findById($parentId);
		$otherRoot = $this->dbRepo->findById($otherRootId);

		$this->assertNotNull($parent);
		$this->assertNotNull($otherRoot);

		// parent shrinks rightId from 4 to 2
		$this->assertSame(2, $parent->rightId);

		// otherRoot shifts from 5,6 to 3,4
		$this->assertSame(3, $otherRoot->leftId);
		$this->assertSame(4, $otherRoot->rightId);
	}

	#[Test]
	public function testMoveNode_moveToRoot_updatesPositions(): void
	{
		// Arrange
		$root1Id = $this->insertForum(['forum_name' => 'Root 1']);
		$this->treeService->insertNode($root1Id, 0);
		// root1: left=1, right=2

		$childId = $this->insertForum(['forum_name' => 'Child']);
		$this->treeService->insertNode($childId, $root1Id);
		// child: left=2, right=3; root1: left=1, right=4

		$root2Id = $this->insertForum(['forum_name' => 'Root 2']);
		$this->treeService->insertNode($root2Id, 0);
		// root2: left=5, right=6

		// Act
		$this->treeService->moveNode($childId, 0);

		// Assert: child should now be at root level
		$child = $this->dbRepo->findById($childId);
		$this->assertNotNull($child);
		$this->assertSame(0, $child->parentId);
	}

	#[Test]
	public function testMoveNode_moveUnderNewParent_updatesParentId(): void
	{
		// Arrange
		$root1Id = $this->insertForum(['forum_name' => 'Root 1']);
		$this->treeService->insertNode($root1Id, 0);
		// root1: left=1, right=2

		$root2Id = $this->insertForum(['forum_name' => 'Root 2']);
		$this->treeService->insertNode($root2Id, 0);
		// root2: left=3, right=4

		$childId = $this->insertForum(['forum_name' => 'Child']);
		$this->treeService->insertNode($childId, $root1Id);
		// child: left=2, right=3; root1: left=1, right=4; root2: left=5, right=6

		// Act: move child from root1 to root2
		$this->treeService->moveNode($childId, $root2Id);

		// Assert
		$child = $this->dbRepo->findById($childId);
		$this->assertNotNull($child);
		$this->assertSame($root2Id, $child->parentId);
	}

	#[Test]
	public function testMoveNode_clearsParentsCacheForMovedNode(): void
	{
		// Arrange
		$root1Id = $this->insertForum(['forum_name' => 'Root 1']);
		$this->treeService->insertNode($root1Id, 0);

		$root2Id = $this->insertForum(['forum_name' => 'Root 2']);
		$this->treeService->insertNode($root2Id, 0);

		$childId = $this->insertForum(['forum_name' => 'Child']);
		$this->treeService->insertNode($childId, $root1Id);

		// Set forum_parents to a non-empty value to verify it gets cleared
		$this->connection->executeStatement(
			"UPDATE phpbb_forums SET forum_parents = '[1]' WHERE forum_id = :id",
			['id' => $childId],
		);

		// Act
		$this->treeService->moveNode($childId, $root2Id);

		// Assert: forum_parents must be reset to '[]'
		$row = $this->connection->executeQuery(
			'SELECT forum_parents FROM phpbb_forums WHERE forum_id = :id',
			['id' => $childId],
		)->fetchAssociative();

		$this->assertNotFalse($row);
		$this->assertSame('[]', $row['forum_parents']);
	}
}

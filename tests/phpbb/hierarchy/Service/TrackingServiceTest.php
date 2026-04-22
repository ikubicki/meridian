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
use phpbb\hierarchy\Service\TrackingService;
use phpbb\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;

class TrackingServiceTest extends IntegrationTestCase
{
	private DbalForumRepository $forumRepo;
	private TrackingService $trackingService;

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

		$this->connection->executeStatement('
			CREATE TABLE phpbb_forums_track (
				user_id    INTEGER NOT NULL,
				forum_id   INTEGER NOT NULL,
				mark_time  INTEGER NOT NULL,
				PRIMARY KEY (user_id, forum_id)
			)
		');

		$this->connection->executeStatement('
			CREATE TABLE phpbb_topics_track (
				topic_id              INTEGER PRIMARY KEY,
				forum_id              INTEGER NOT NULL,
				topic_last_post_time  INTEGER NOT NULL
			)
		');

		$this->forumRepo = new DbalForumRepository($this->connection);
		$this->trackingService = new TrackingService($this->connection, $this->forumRepo);
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
	public function testMarkForumRead_insertsTrackingRow(): void
	{
		// Act
		$this->trackingService->markForumRead(1, 5);

		// Assert
		$count = $this->connection->executeQuery(
			'SELECT COUNT(*) FROM phpbb_forums_track WHERE forum_id = 1 AND user_id = 5'
		)->fetchOne();

		$this->assertSame(1, (int) $count);
	}

	#[Test]
	public function testMarkForumRead_calledTwice_updatesNotDuplicates(): void
	{
		// Act
		$this->trackingService->markForumRead(1, 5);
		$this->trackingService->markForumRead(1, 5);

		// Assert
		$count = $this->connection->executeQuery(
			'SELECT COUNT(*) FROM phpbb_forums_track WHERE forum_id = 1 AND user_id = 5'
		)->fetchOne();

		$this->assertSame(1, (int) $count);
	}

	#[Test]
	public function testHasUnread_neverMarked_withPosts_returnsTrue(): void
	{
		// Arrange
		$forumId = $this->insertForum(['forum_posts_approved' => 5]);

		// Act
		$result = $this->trackingService->hasUnread($forumId, 99);

		// Assert
		$this->assertTrue($result);
	}

	#[Test]
	public function testHasUnread_neverMarked_noPosts_returnsFalse(): void
	{
		// Arrange
		$forumId = $this->insertForum(['forum_posts_approved' => 0]);

		// Act
		$result = $this->trackingService->hasUnread($forumId, 99);

		// Assert
		$this->assertFalse($result);
	}

	#[Test]
	public function testHasUnread_markedRecently_noNewTopics_returnsFalse(): void
	{
		// Arrange
		$forumId = $this->insertForum();
		$this->trackingService->markForumRead($forumId, 5);

		// Act
		$result = $this->trackingService->hasUnread($forumId, 5);

		// Assert
		$this->assertFalse($result);
	}

	#[Test]
	public function testHasUnread_markedEarlier_hasNewerTopic_returnsTrue(): void
	{
		// Arrange
		$forumId = $this->insertForum();

		// Insert tracking row with mark_time=100
		$this->connection->executeStatement(
			'INSERT INTO phpbb_forums_track (user_id, forum_id, mark_time) VALUES (:userId, :forumId, :markTime)',
			['userId' => 5, 'forumId' => $forumId, 'markTime' => 100]
		);

		// Insert a topic newer than mark_time
		$this->connection->executeStatement(
			'INSERT INTO phpbb_topics_track (topic_id, forum_id, topic_last_post_time) VALUES (:topicId, :forumId, :lastPostTime)',
			['topicId' => 1, 'forumId' => $forumId, 'lastPostTime' => 200]
		);

		// Act
		$result = $this->trackingService->hasUnread($forumId, 5);

		// Assert
		$this->assertTrue($result);
	}
}

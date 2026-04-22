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

namespace phpbb\Tests\hierarchy\Entity;

use phpbb\hierarchy\Entity\Forum;
use phpbb\hierarchy\Entity\ForumLastPost;
use phpbb\hierarchy\Entity\ForumPruneSettings;
use phpbb\hierarchy\Entity\ForumStats;
use phpbb\hierarchy\Entity\ForumStatus;
use phpbb\hierarchy\Entity\ForumType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ForumTest extends TestCase
{
	private function makeMinimalForum(array $overrides = []): Forum
	{
		$defaults = [
			'id'                   => 1,
			'name'                 => 'Test',
			'description'          => '',
			'descriptionBitfield'  => '',
			'descriptionOptions'   => 7,
			'descriptionUid'       => '',
			'parentId'             => 0,
			'leftId'               => 1,
			'rightId'              => 2,
			'type'                 => ForumType::Forum,
			'status'               => ForumStatus::Unlocked,
			'image'                => '',
			'rules'                => '',
			'rulesLink'            => '',
			'rulesBitfield'        => '',
			'rulesOptions'         => 7,
			'rulesUid'             => '',
			'link'                 => '',
			'password'             => '',
			'style'                => 0,
			'topicsPerPage'        => 0,
			'flags'                => 32,
			'options'              => 0,
			'displayOnIndex'       => true,
			'displaySubforumList'  => true,
			'enableIndexing'       => true,
			'enableIcons'          => false,
			'stats'                => new ForumStats(0, 0, 0, 0, 0, 0),
			'lastPost'             => new ForumLastPost(0, 0, '', 0, '', ''),
			'pruneSettings'        => new ForumPruneSettings(false, 0, 0, 0, 0),
			'parents'              => [],
		];

		$data = array_merge($defaults, $overrides);

		return new Forum(
			id: $data['id'],
			name: $data['name'],
			description: $data['description'],
			descriptionBitfield: $data['descriptionBitfield'],
			descriptionOptions: $data['descriptionOptions'],
			descriptionUid: $data['descriptionUid'],
			parentId: $data['parentId'],
			leftId: $data['leftId'],
			rightId: $data['rightId'],
			type: $data['type'],
			status: $data['status'],
			image: $data['image'],
			rules: $data['rules'],
			rulesLink: $data['rulesLink'],
			rulesBitfield: $data['rulesBitfield'],
			rulesOptions: $data['rulesOptions'],
			rulesUid: $data['rulesUid'],
			link: $data['link'],
			password: $data['password'],
			style: $data['style'],
			topicsPerPage: $data['topicsPerPage'],
			flags: $data['flags'],
			options: $data['options'],
			displayOnIndex: $data['displayOnIndex'],
			displaySubforumList: $data['displaySubforumList'],
			enableIndexing: $data['enableIndexing'],
			enableIcons: $data['enableIcons'],
			stats: $data['stats'],
			lastPost: $data['lastPost'],
			pruneSettings: $data['pruneSettings'],
			parents: $data['parents'],
		);
	}

	#[Test]
	public function testIsLeaf_leafNode_returnsTrue(): void
	{
		// Arrange
		$forum = $this->makeMinimalForum(['leftId' => 1, 'rightId' => 2]);

		// Act & Assert
		$this->assertTrue($forum->isLeaf());
	}

	#[Test]
	public function testIsLeaf_nonLeafNode_returnsFalse(): void
	{
		// Arrange
		$forum = $this->makeMinimalForum(['leftId' => 1, 'rightId' => 6]);

		// Act & Assert
		$this->assertFalse($forum->isLeaf());
	}

	#[Test]
	public function testDescendantCount_twoChildren_returnsTwo(): void
	{
		// Arrange
		$forum = $this->makeMinimalForum(['leftId' => 1, 'rightId' => 6]);

		// Act & Assert
		$this->assertSame(2, $forum->descendantCount());
	}

	#[Test]
	public function testDescendantCount_leafNode_returnsZero(): void
	{
		// Arrange
		$forum = $this->makeMinimalForum(['leftId' => 3, 'rightId' => 4]);

		// Act & Assert
		$this->assertSame(0, $forum->descendantCount());
	}

	#[Test]
	public function testIsCategory_categoryType_returnsTrue(): void
	{
		// Arrange
		$forum = $this->makeMinimalForum(['type' => ForumType::Category]);

		// Act & Assert
		$this->assertTrue($forum->isCategory());
		$this->assertFalse($forum->isForum());
		$this->assertFalse($forum->isLink());
	}

	#[Test]
	public function testIsForum_forumType_returnsTrue(): void
	{
		// Arrange
		$forum = $this->makeMinimalForum(['type' => ForumType::Forum]);

		// Act & Assert
		$this->assertTrue($forum->isForum());
	}

	#[Test]
	public function testIsLink_linkType_returnsTrue(): void
	{
		// Arrange
		$forum = $this->makeMinimalForum(['type' => ForumType::Link]);

		// Act & Assert
		$this->assertTrue($forum->isLink());
	}

	#[Test]
	public function testForumStats_totalPosts_sumsAllCounters(): void
	{
		// Arrange
		$stats = new ForumStats(
			postsApproved: 1,
			postsUnapproved: 2,
			postsSoftdeleted: 3,
			topicsApproved: 4,
			topicsUnapproved: 5,
			topicsSoftdeleted: 6,
		);

		// Act & Assert
		$this->assertSame(6, $stats->totalPosts());
		$this->assertSame(15, $stats->totalTopics());
	}
}

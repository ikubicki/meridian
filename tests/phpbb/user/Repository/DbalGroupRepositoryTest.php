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

namespace phpbb\Tests\user\Repository;

use phpbb\Tests\Integration\IntegrationTestCase;
use phpbb\user\Enum\GroupType;
use phpbb\user\Repository\DbalGroupRepository;
use PHPUnit\Framework\Attributes\Test;

final class DbalGroupRepositoryTest extends IntegrationTestCase
{
	private DbalGroupRepository $repository;

	protected function setUpSchema(): void
	{
		$this->connection->executeStatement('
			CREATE TABLE phpbb_groups (
				group_id           INTEGER PRIMARY KEY AUTOINCREMENT,
				group_type         INTEGER NOT NULL DEFAULT 0,
				group_name         TEXT    NOT NULL DEFAULT \'\',
				group_desc         TEXT    NOT NULL DEFAULT \'\',
				group_display      INTEGER NOT NULL DEFAULT 0,
				group_legend       INTEGER NOT NULL DEFAULT 0,
				group_colour       TEXT    NOT NULL DEFAULT \'\',
				group_rank         INTEGER NOT NULL DEFAULT 0,
				group_avatar       TEXT    NOT NULL DEFAULT \'\',
				group_receive_pm   INTEGER NOT NULL DEFAULT 0,
				group_message_limit   INTEGER NOT NULL DEFAULT 0,
				group_max_recipients  INTEGER NOT NULL DEFAULT 0,
				group_founder_manage  INTEGER NOT NULL DEFAULT 0,
				group_skip_auth       INTEGER NOT NULL DEFAULT 0,
				group_teampage        INTEGER NOT NULL DEFAULT 0
			)
		');

		$this->connection->executeStatement('
			CREATE TABLE phpbb_user_group (
				group_id     INTEGER NOT NULL,
				user_id      INTEGER NOT NULL,
				group_leader INTEGER NOT NULL DEFAULT 0,
				user_pending INTEGER NOT NULL DEFAULT 0,
				PRIMARY KEY (group_id, user_id)
			)
		');

		$this->repository = new DbalGroupRepository($this->connection);
	}

	private function insertGroup(array $data = []): int
	{
		$defaults = [
			'group_type'         => 0,
			'group_name'         => 'Test Group',
			'group_desc'         => '',
			'group_display'      => 0,
			'group_legend'       => 0,
			'group_colour'       => '',
			'group_rank'         => 0,
			'group_avatar'       => '',
			'group_receive_pm'   => 0,
			'group_message_limit'   => 0,
			'group_max_recipients'  => 0,
			'group_founder_manage'  => 0,
			'group_skip_auth'       => 0,
			'group_teampage'        => 0,
		];
		$row = array_merge($defaults, $data);

		$this->connection->executeStatement(
			'INSERT INTO phpbb_groups
				(group_type, group_name, group_desc, group_display, group_legend, group_colour,
				 group_rank, group_avatar, group_receive_pm, group_message_limit, group_max_recipients,
				 group_founder_manage, group_skip_auth, group_teampage)
			 VALUES
				(:group_type, :group_name, :group_desc, :group_display, :group_legend, :group_colour,
				 :group_rank, :group_avatar, :group_receive_pm, :group_message_limit, :group_max_recipients,
				 :group_founder_manage, :group_skip_auth, :group_teampage)',
			$row,
		);

		return (int) $this->connection->lastInsertId();
	}

	#[Test]
	public function testFindById_found_returnsHydratedGroup(): void
	{
		$id = $this->insertGroup([
			'group_name'    => 'Administrators',
			'group_type'    => 3,
			'group_colour'  => 'AA0000',
			'group_rank'    => 2,
			'group_teampage' => 1,
		]);

		$group = $this->repository->findById($id);

		self::assertNotNull($group);
		self::assertSame($id, $group->id);
		self::assertSame('Administrators', $group->name);
		self::assertSame(GroupType::Special, $group->type);
		self::assertSame('AA0000', $group->colour);
		self::assertSame(2, $group->rank);
		self::assertSame(1, $group->teamPage);
	}

	#[Test]
	public function testFindById_notFound_returnsNull(): void
	{
		$result = $this->repository->findById(99999);

		self::assertNull($result);
	}

	#[Test]
	public function testFindAll_noFilter_returnsAllGroups(): void
	{
		$this->insertGroup(['group_name' => 'Alpha']);
		$this->insertGroup(['group_name' => 'Beta']);
		$this->insertGroup(['group_name' => 'Gamma']);

		$groups = $this->repository->findAll(null);

		self::assertCount(3, $groups);
	}

	#[Test]
	public function testFindAll_withTypeFilter_returnsOnlyMatchingGroups(): void
	{
		// GroupType::Open = 0, GroupType::Closed = 1
		$this->insertGroup(['group_name' => 'Open Group 1', 'group_type' => 0]);
		$this->insertGroup(['group_name' => 'Open Group 2', 'group_type' => 0]);
		$this->insertGroup(['group_name' => 'Closed Group', 'group_type' => 1]);

		$groups = $this->repository->findAll(GroupType::Open);

		self::assertCount(2, $groups);
		foreach ($groups as $group) {
			self::assertSame(GroupType::Open, $group->type);
		}
	}

	#[Test]
	public function testGetMembershipsForUser_returnsCorrectMemberships(): void
	{
		$groupId1 = $this->insertGroup(['group_name' => 'Group A']);
		$groupId2 = $this->insertGroup(['group_name' => 'Group B']);

		$this->connection->executeStatement(
			'INSERT INTO phpbb_user_group (group_id, user_id, group_leader, user_pending) VALUES (:groupId, :userId, 0, 0)',
			['groupId' => $groupId1, 'userId' => 1],
		);
		$this->connection->executeStatement(
			'INSERT INTO phpbb_user_group (group_id, user_id, group_leader, user_pending) VALUES (:groupId, :userId, 0, 0)',
			['groupId' => $groupId2, 'userId' => 1],
		);

		$memberships = $this->repository->getMembershipsForUser(1);

		self::assertCount(2, $memberships);
	}

	#[Test]
	public function testAddMember_insert_idempotency(): void
	{
		$groupId = $this->insertGroup();

		$this->repository->addMember($groupId, 1, false);
		$this->repository->addMember($groupId, 1, false);

		$count = $this->connection->executeQuery(
			'SELECT COUNT(*) FROM phpbb_user_group WHERE group_id = :groupId AND user_id = :userId',
			['groupId' => $groupId, 'userId' => 1],
		)->fetchOne();

		self::assertSame(1, (int) $count);
	}

	#[Test]
	public function testAddMember_leaderPromotion(): void
	{
		$groupId = $this->insertGroup();

		$this->repository->addMember($groupId, 1, false);
		$this->repository->addMember($groupId, 1, true);

		$leader = $this->connection->executeQuery(
			'SELECT group_leader FROM phpbb_user_group WHERE group_id = :groupId AND user_id = :userId',
			['groupId' => $groupId, 'userId' => 1],
		)->fetchOne();

		self::assertSame(1, (int) $leader);
	}

	#[Test]
	public function testRemoveMember_deletesMembershipRow(): void
	{
		$groupId = $this->insertGroup();

		$this->repository->addMember($groupId, 1, false);
		$this->repository->removeMember($groupId, 1);

		$count = $this->connection->executeQuery(
			'SELECT COUNT(*) FROM phpbb_user_group WHERE group_id = :groupId AND user_id = :userId',
			['groupId' => $groupId, 'userId' => 1],
		)->fetchOne();

		self::assertSame(0, (int) $count);
	}
}

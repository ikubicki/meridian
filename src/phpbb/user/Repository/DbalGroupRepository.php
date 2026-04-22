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

namespace phpbb\user\Repository;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use phpbb\db\Exception\RepositoryException;
use phpbb\user\Contract\GroupRepositoryInterface;
use phpbb\user\Entity\Group;
use phpbb\user\Entity\GroupMembership;
use phpbb\user\Enum\GroupType;

class DbalGroupRepository implements GroupRepositoryInterface
{
	private const TABLE       = 'phpbb_groups';
	private const TABLE_PIVOT = 'phpbb_user_group';

	public function __construct(
		private readonly \Doctrine\DBAL\Connection $connection,
	) {
	}

	public function findById(int $id): ?Group
	{
		try {
			$row = $this->connection->executeQuery(
				'SELECT * FROM ' . self::TABLE . ' WHERE group_id = :id LIMIT 1',
				['id' => $id],
			)->fetchAssociative();

			return $row !== false ? $this->hydrate($row) : null;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find group by ID', previous: $e);
		}
	}

	public function findAll(?GroupType $type = null): array
	{
		try {
			if ($type !== null) {
				$rows = $this->connection->executeQuery(
					'SELECT * FROM ' . self::TABLE . ' WHERE group_type = :type ORDER BY group_name ASC',
					['type' => $type->value],
				)->fetchAllAssociative();
			} else {
				$rows = $this->connection->executeQuery(
					'SELECT * FROM ' . self::TABLE . ' ORDER BY group_name ASC',
				)->fetchAllAssociative();
			}

			return array_map([$this, 'hydrate'], $rows);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find groups', previous: $e);
		}
	}

	public function getMembershipsForUser(int $userId): array
	{
		try {
			$rows = $this->connection->executeQuery(
				'SELECT * FROM ' . self::TABLE_PIVOT . ' WHERE user_id = :userId',
				['userId' => $userId],
			)->fetchAllAssociative();

			$memberships = [];
			foreach ($rows as $row) {
				$memberships[] = new GroupMembership(
					groupId: (int) $row['group_id'],
					userId: (int) $row['user_id'],
					isLeader: (bool) $row['group_leader'],
					isPending: (bool) $row['user_pending'],
				);
			}

			return $memberships;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to get memberships for user', previous: $e);
		}
	}

	public function addMember(int $groupId, int $userId, bool $isLeader = false): void
	{
		try {
			$platform = $this->connection->getDatabasePlatform();
			if ($platform instanceof MySQLPlatform) {
				$this->connection->executeStatement(
					'INSERT INTO ' . self::TABLE_PIVOT .
					' (group_id, user_id, group_leader, user_pending)
					  VALUES (:groupId, :userId, :isLeader, 0)
					  ON DUPLICATE KEY UPDATE group_leader = :isLeader, user_pending = 0',
					['groupId' => $groupId, 'userId' => $userId, 'isLeader' => (int) $isLeader],
				);
			} else {
				$this->connection->transactional(function (\Doctrine\DBAL\Connection $conn) use ($groupId, $userId, $isLeader): void {
					$conn->executeStatement(
						'DELETE FROM ' . self::TABLE_PIVOT . ' WHERE group_id = :groupId AND user_id = :userId',
						['groupId' => $groupId, 'userId' => $userId],
					);
					$conn->executeStatement(
						'INSERT INTO ' . self::TABLE_PIVOT . ' (group_id, user_id, group_leader, user_pending) VALUES (:groupId, :userId, :isLeader, 0)',
						['groupId' => $groupId, 'userId' => $userId, 'isLeader' => (int) $isLeader],
					);
				});
			}
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to add member to group', previous: $e);
		}
	}

	public function removeMember(int $groupId, int $userId): void
	{
		try {
			$this->connection->executeStatement(
				'DELETE FROM ' . self::TABLE_PIVOT . ' WHERE group_id = :groupId AND user_id = :userId',
				['groupId' => $groupId, 'userId' => $userId],
			);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to remove member from group', previous: $e);
		}
	}

	private function hydrate(array $row): Group
	{
		return new Group(
			id: (int) $row['group_id'],
			type: GroupType::from((int) $row['group_type']),
			name: $row['group_name'],
			description: $row['group_desc'],
			displayOnIndex: (bool) $row['group_display'],
			legend: (bool) $row['group_legend'],
			colour: $row['group_colour'],
			rank: (int) $row['group_rank'],
			avatar: $row['group_avatar'],
			receivePm: (bool) $row['group_receive_pm'],
			messageLimit: (int) $row['group_message_limit'],
			maxRecipients: (int) $row['group_max_recipients'],
			founderManage: (bool) $row['group_founder_manage'],
			skipAuth: (bool) $row['group_skip_auth'],
			teamPage: (int) $row['group_teampage'],
		);
	}
}

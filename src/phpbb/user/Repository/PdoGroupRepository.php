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

use phpbb\user\Contract\GroupRepositoryInterface;
use phpbb\user\Entity\Group;
use phpbb\user\Entity\GroupMembership;
use phpbb\user\Enum\GroupType;

class PdoGroupRepository implements GroupRepositoryInterface
{
	private const TABLE       = 'phpbb_groups';
	private const TABLE_PIVOT = 'phpbb_user_group';

	public function __construct(
		private readonly \PDO $pdo,
	) {
	}

	public function findById(int $id): ?Group
	{
		$stmt = $this->pdo->prepare(
			'SELECT * FROM ' . self::TABLE . ' WHERE group_id = :id LIMIT 1',
		);
		$stmt->execute([':id' => $id]);
		$row = $stmt->fetch();

		return $row !== false ? $this->hydrate($row) : null;
	}

	public function findAll(?GroupType $type = null): array
	{
		if ($type !== null)
		{
			$stmt = $this->pdo->prepare(
				'SELECT * FROM ' . self::TABLE . ' WHERE group_type = :type ORDER BY group_name ASC',
			);
			$stmt->execute([':type' => $type->value]);
		}
		else
		{
			$stmt = $this->pdo->query('SELECT * FROM ' . self::TABLE . ' ORDER BY group_name ASC');
		}

		return array_map([$this, 'hydrate'], $stmt->fetchAll());
	}

	public function getMembershipsForUser(int $userId): array
	{
		$stmt = $this->pdo->prepare(
			'SELECT * FROM ' . self::TABLE_PIVOT . ' WHERE user_id = :userId',
		);
		$stmt->execute([':userId' => $userId]);

		$memberships = [];
		foreach ($stmt->fetchAll() as $row)
		{
			$memberships[] = new GroupMembership(
				groupId: (int) $row['group_id'],
				userId: (int) $row['user_id'],
				isLeader: (bool) $row['group_leader'],
				isPending: (bool) $row['user_pending'],
			);
		}

		return $memberships;
	}

	public function addMember(int $groupId, int $userId, bool $isLeader = false): void
	{
		$stmt = $this->pdo->prepare(
			'INSERT INTO ' . self::TABLE_PIVOT .
			' (group_id, user_id, group_leader, user_pending)
			  VALUES (:groupId, :userId, :isLeader, 0)
			  ON DUPLICATE KEY UPDATE group_leader = :isLeader, user_pending = 0',
		);
		$stmt->execute([
			':groupId'  => $groupId,
			':userId'   => $userId,
			':isLeader' => (int) $isLeader,
		]);
	}

	public function removeMember(int $groupId, int $userId): void
	{
		$stmt = $this->pdo->prepare(
			'DELETE FROM ' . self::TABLE_PIVOT . ' WHERE group_id = :groupId AND user_id = :userId',
		);
		$stmt->execute([':groupId' => $groupId, ':userId' => $userId]);
	}

	/** @param array<string, mixed> $row */
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

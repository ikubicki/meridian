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

namespace phpbb\user\Contract;

use phpbb\user\Entity\Group;
use phpbb\user\Entity\GroupMembership;
use phpbb\user\Enum\GroupType;

interface GroupRepositoryInterface
{
	public function findById(int $id): ?Group;

	/**
	 * @return list<Group>
	 */
	public function findAll(?GroupType $type = null): array;

	/**
	 * @return list<GroupMembership>
	 */
	public function getMembershipsForUser(int $userId): array;

	public function addMember(int $groupId, int $userId, bool $isLeader = false): void;

	public function removeMember(int $groupId, int $userId): void;
}

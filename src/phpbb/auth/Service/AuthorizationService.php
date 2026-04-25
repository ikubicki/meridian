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

namespace phpbb\auth\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use phpbb\auth\Contract\AuthorizationServiceInterface;
use phpbb\user\Entity\User;

final class AuthorizationService implements AuthorizationServiceInterface
{
	public function __construct(private readonly Connection $connection)
	{
	}

	/**
	 * Resolves whether $user holds $permission, optionally scoped to $forumId.
	 *
	 * Algorithm mirrors phpBB core ACL resolution:
	 *  1. Collect group-level permissions via direct option grants + roles.
	 *  2. Collect user-level overrides (same two paths).
	 *  3. User-level entry wins over group-level.
	 *  4. auth_setting 1 = allow, -1 = deny, 0 = no-override (inherit).
	 *  5. Both global (forum_id=0) and forum-scoped rows are considered;
	 *     a forum-scoped allow beats a global deny and vice versa — first
	 *     explicit "allow" on any relevant row wins (phpBB "any-allow" model).
	 */
	public function isGranted(User $user, string $permission, int $forumId = 0): bool
	{
		$forumScope = $forumId > 0 ? [$forumId, 0] : [0];

		// Step 1 – group-level permissions
		$groupSetting = $this->resolveGroupPermission($user->id, $permission, $forumScope);

		// Step 2 – user-level overrides
		$userSetting = $this->resolveUserPermission($user->id, $permission, $forumScope);

		// User-level override wins; fall back to group-level
		$effective = $userSetting ?? $groupSetting;

		return $effective === 1;
	}

	/** @param int[] $forumScope */
	private function resolveGroupPermission(int $userId, string $permission, array $forumScope): ?int
	{
		// Direct option grant on a group the user belongs to
		$qb = $this->connection->createQueryBuilder();
		$row = $qb->select('ag.auth_setting')
			->from('phpbb_user_group', 'ug')
			->join('ug', 'phpbb_acl_groups', 'ag', 'ag.group_id = ug.group_id')
			->join('ag', 'phpbb_acl_options', 'ao', 'ao.auth_option_id = ag.auth_option_id')
			->where($qb->expr()->eq('ug.user_id', ':userId'))
			->andWhere($qb->expr()->eq('ug.user_pending', '0'))
			->andWhere($qb->expr()->eq('ao.auth_option', ':permission'))
			->andWhere('ag.forum_id IN (:forumScope)')
			->orderBy('ag.auth_setting', 'DESC')
			->setParameter('userId', $userId)
			->setParameter('permission', $permission)
			->setParameter('forumScope', $forumScope, ArrayParameterType::INTEGER)
			->executeQuery()
			->fetchOne();

		if ($row !== false) {
			return (int) $row;
		}

		// Role-based grant on a group the user belongs to
		$qb2 = $this->connection->createQueryBuilder();
		$row = $qb2->select('rd.auth_setting')
			->from('phpbb_user_group', 'ug')
			->join('ug', 'phpbb_acl_groups', 'ag', 'ag.group_id = ug.group_id')
			->join('ag', 'phpbb_acl_roles_data', 'rd', 'rd.role_id = ag.auth_role_id')
			->join('rd', 'phpbb_acl_options', 'ao', 'ao.auth_option_id = rd.auth_option_id')
			->where($qb2->expr()->eq('ug.user_id', ':userId'))
			->andWhere($qb2->expr()->eq('ug.user_pending', '0'))
			->andWhere($qb2->expr()->gt('ag.auth_role_id', '0'))
			->andWhere($qb2->expr()->eq('ao.auth_option', ':permission'))
			->andWhere('ag.forum_id IN (:forumScope)')
			->orderBy('rd.auth_setting', 'DESC')
			->setParameter('userId', $userId)
			->setParameter('permission', $permission)
			->setParameter('forumScope', $forumScope, ArrayParameterType::INTEGER)
			->executeQuery()
			->fetchOne();

		return $row !== false ? (int) $row : null;
	}

	/** @param int[] $forumScope */
	private function resolveUserPermission(int $userId, string $permission, array $forumScope): ?int
	{
		// Direct option override on the user
		$qb = $this->connection->createQueryBuilder();
		$row = $qb->select('au.auth_setting')
			->from('phpbb_acl_users', 'au')
			->join('au', 'phpbb_acl_options', 'ao', 'ao.auth_option_id = au.auth_option_id')
			->where($qb->expr()->eq('au.user_id', ':userId'))
			->andWhere($qb->expr()->eq('ao.auth_option', ':permission'))
			->andWhere('au.forum_id IN (:forumScope)')
			->orderBy('au.auth_setting', 'DESC')
			->setParameter('userId', $userId)
			->setParameter('permission', $permission)
			->setParameter('forumScope', $forumScope, ArrayParameterType::INTEGER)
			->executeQuery()
			->fetchOne();

		if ($row !== false) {
			return (int) $row;
		}

		// Role-based override on the user
		$qb2 = $this->connection->createQueryBuilder();
		$row = $qb2->select('rd.auth_setting')
			->from('phpbb_acl_users', 'au')
			->join('au', 'phpbb_acl_roles_data', 'rd', 'rd.role_id = au.auth_role_id')
			->join('rd', 'phpbb_acl_options', 'ao', 'ao.auth_option_id = rd.auth_option_id')
			->where($qb2->expr()->eq('au.user_id', ':userId'))
			->andWhere($qb2->expr()->gt('au.auth_role_id', '0'))
			->andWhere($qb2->expr()->eq('ao.auth_option', ':permission'))
			->andWhere('au.forum_id IN (:forumScope)')
			->orderBy('rd.auth_setting', 'DESC')
			->setParameter('userId', $userId)
			->setParameter('permission', $permission)
			->setParameter('forumScope', $forumScope, ArrayParameterType::INTEGER)
			->executeQuery()
			->fetchOne();

		return $row !== false ? (int) $row : null;
	}
}

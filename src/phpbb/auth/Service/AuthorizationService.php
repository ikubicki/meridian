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
		$placeholders = implode(',', array_fill(0, count($forumScope), '?'));

		// Direct option grant on a group the user belongs to
		$sql = "
			SELECT ag.auth_setting
			FROM phpbb_user_group ug
			JOIN phpbb_acl_groups ag ON ag.group_id = ug.group_id
			JOIN phpbb_acl_options ao ON ao.auth_option_id = ag.auth_option_id
			WHERE ug.user_id = ?
			  AND ug.user_pending = 0
			  AND ao.auth_option = ?
			  AND ag.forum_id IN ($placeholders)
			ORDER BY ag.auth_setting DESC
		";

		$params = array_merge([$userId, $permission], $forumScope);
		$row    = $this->connection->fetchOne($sql, $params);

		if ($row !== false) {
			return (int) $row;
		}

		// Role-based grant on a group the user belongs to
		$sql = "
			SELECT rd.auth_setting
			FROM phpbb_user_group ug
			JOIN phpbb_acl_groups ag ON ag.group_id = ug.group_id
			JOIN phpbb_acl_roles_data rd ON rd.role_id = ag.auth_role_id
			JOIN phpbb_acl_options ao ON ao.auth_option_id = rd.auth_option_id
			WHERE ug.user_id = ?
			  AND ug.user_pending = 0
			  AND ag.auth_role_id > 0
			  AND ao.auth_option = ?
			  AND ag.forum_id IN ($placeholders)
			ORDER BY rd.auth_setting DESC
		";

		$row = $this->connection->fetchOne($sql, $params);

		return $row !== false ? (int) $row : null;
	}

	/** @param int[] $forumScope */
	private function resolveUserPermission(int $userId, string $permission, array $forumScope): ?int
	{
		$placeholders = implode(',', array_fill(0, count($forumScope), '?'));

		// Direct option override on the user
		$sql = "
			SELECT au.auth_setting
			FROM phpbb_acl_users au
			JOIN phpbb_acl_options ao ON ao.auth_option_id = au.auth_option_id
			WHERE au.user_id = ?
			  AND ao.auth_option = ?
			  AND au.forum_id IN ($placeholders)
			ORDER BY au.auth_setting DESC
		";

		$params = [$userId, $permission, ...$forumScope];
		$row    = $this->connection->fetchOne($sql, $params);

		if ($row !== false) {
			return (int) $row;
		}

		// Role-based override on the user
		$sql = "
			SELECT rd.auth_setting
			FROM phpbb_acl_users au
			JOIN phpbb_acl_roles_data rd ON rd.role_id = au.auth_role_id
			JOIN phpbb_acl_options ao ON ao.auth_option_id = rd.auth_option_id
			WHERE au.user_id = ?
			  AND au.auth_role_id > 0
			  AND ao.auth_option = ?
			  AND au.forum_id IN ($placeholders)
			ORDER BY rd.auth_setting DESC
		";

		$row = $this->connection->fetchOne($sql, $params);

		return $row !== false ? (int) $row : null;
	}
}

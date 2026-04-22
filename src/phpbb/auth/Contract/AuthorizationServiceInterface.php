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

namespace phpbb\auth\Contract;

use phpbb\user\Entity\User;

interface AuthorizationServiceInterface
{
	/**
	 * Returns true if the given user holds the specified permission.
	 *
	 * @param int $forumId Forum scope (0 = global)
	 */
	public function isGranted(User $user, string $permission, int $forumId = 0): bool;
}

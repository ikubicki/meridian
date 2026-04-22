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

namespace phpbb\user\DTO;

use phpbb\user\Enum\UserType;

/**
 * Encapsulates all filtering, sorting, and pagination parameters
 * for user list queries.
 */
final readonly class UserSearchCriteria
{
	public function __construct(
		public ?string $query = null,
		public ?UserType $type = null,
		public ?int $groupId = null,
		public int $page = 1,
		public int $perPage = 25,
		public string $sort = 'username',
		public string $sortOrder = 'asc',
	) {
	}
}

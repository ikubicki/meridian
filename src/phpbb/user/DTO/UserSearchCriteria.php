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
use Symfony\Component\HttpFoundation\ParameterBag;

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

	/**
	 * Build from a Symfony Request query string.
	 * Use this in controllers instead of constructing manually.
	 */
	public static function fromQuery(ParameterBag $query): self
	{
		return new self(
			query: $query->get('q'),
			page: max(1, (int) $query->get('page', 1)),
			perPage: min(100, max(1, (int) $query->get('perPage', 25))),
			sort: $query->get('sort', 'username'),
			sortOrder: in_array(strtolower((string) $query->get('order', 'asc')), ['asc', 'desc'], true)
				? strtolower((string) $query->get('order', 'asc'))
				: 'asc',
		);
	}
}

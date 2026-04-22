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

namespace phpbb\api\DTO;

/**
 * Universal pagination + search context passed from controllers to service methods.
 *
 * Controllers must never pass raw $page / $perPage integers as separate arguments.
 * All list/search actions must build a PaginationContext and forward it to the service.
 *
 * Service methods accept PaginationContext as the first or only parameter for list queries.
 * Domain-specific filter DTOs (e.g. UserSearchCriteria) should embed or extend this class.
 */
final readonly class PaginationContext
{
	public function __construct(
		public int $page = 1,
		public int $perPage = 25,
		public ?string $sort = null,
		public string $sortOrder = 'asc',
	) {
	}

	/**
	 * Build from a Symfony Request query string via named constructor.
	 *
	 * Usage in a controller:
	 *   $ctx = PaginationContext::fromQuery($request->query);
	 */
	public static function fromQuery(\Symfony\Component\HttpFoundation\ParameterBag $query): self
	{
		return new self(
			page: max(1, (int) $query->get('page', 1)),
			perPage: min(100, max(1, (int) $query->get('perPage', 25))),
			sort: $query->get('sort'),
			sortOrder: in_array(strtolower((string) $query->get('order', 'asc')), ['asc', 'desc'], true)
				? strtolower((string) $query->get('order', 'asc'))
				: 'asc',
		);
	}
}

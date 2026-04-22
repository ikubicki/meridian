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

/**
 * Generic paginated result wrapper.
 *
 * @template T
 */
final readonly class PaginatedResult
{
	/**
	 * @param list<T> $items
	 */
	public function __construct(
		public array $items,
		public int $total,
		public int $page,
		public int $perPage,
	) {
	}

	public function totalPages(): int
	{
		if ($this->perPage <= 0)
		{
			return 0;
		}
		return (int) ceil($this->total / $this->perPage);
	}
}

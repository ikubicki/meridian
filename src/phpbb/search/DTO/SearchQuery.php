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

namespace phpbb\search\DTO;

readonly class SearchQuery
{
	private const SORT_VALUES = ['date_desc', 'date_asc', 'relevance'];
	private const SEARCH_IN_VALUES = ['both', 'posts', 'titles', 'first_post'];

	public function __construct(
		public string $keywords,
		public ?int   $forumId   = null,
		public ?int   $topicId   = null,
		public ?int   $userId    = null,
		public string $sortBy    = 'date_desc',
		public string $searchIn  = 'both',
		public ?int   $dateFrom  = null,
		public ?int   $dateTo    = null,
	) {
		if (!in_array($sortBy, self::SORT_VALUES, true)) {
			throw new \InvalidArgumentException(
				sprintf('Invalid sortBy value "%s". Allowed: %s', $sortBy, implode(', ', self::SORT_VALUES))
			);
		}
		if (!in_array($searchIn, self::SEARCH_IN_VALUES, true)) {
			throw new \InvalidArgumentException(
				sprintf('Invalid searchIn value "%s". Allowed: %s', $searchIn, implode(', ', self::SEARCH_IN_VALUES))
			);
		}
	}
}

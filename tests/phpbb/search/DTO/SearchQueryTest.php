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

namespace phpbb\Tests\search\DTO;

use InvalidArgumentException;
use phpbb\search\DTO\SearchQuery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SearchQueryTest extends TestCase
{
	#[Test]
	public function it_default_sort_by_is_date_desc(): void
	{
		$query = new SearchQuery('hello');

		$this->assertSame('date_desc', $query->sortBy);
	}

	#[Test]
	public function it_invalid_sort_by_throws_exception(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new SearchQuery('hello', sortBy: 'invalid');
	}

	#[Test]
	public function it_invalid_search_in_throws_exception(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new SearchQuery('hello', searchIn: 'invalid');
	}
}

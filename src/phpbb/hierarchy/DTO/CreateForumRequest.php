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

namespace phpbb\hierarchy\DTO;

use phpbb\hierarchy\Entity\ForumType;

class CreateForumRequest
{
	private array $extra = [];

	public function __construct(
		public readonly string $name,
		public readonly ForumType $type,
		public readonly int $parentId = 0,
		public readonly int $actorId = 0,
		public readonly string $description = '',
		public readonly string $link = '',
		public readonly string $image = '',
		public readonly string $rules = '',
		public readonly string $rulesLink = '',
		public readonly string $password = '',
		public readonly int $style = 0,
		public readonly int $topicsPerPage = 0,
		public readonly int $flags = 32,
		public readonly bool $displayOnIndex = true,
		public readonly bool $displaySubforumList = true,
		public readonly bool $enableIndexing = true,
		public readonly bool $enableIcons = false,
	) {
	}

	public function withExtra(string $key, mixed $value): static
	{
		$clone = clone $this;
		$clone->extra[$key] = $value;

		return $clone;
	}

	public function getExtra(string $key, mixed $default = null): mixed
	{
		return $this->extra[$key] ?? $default;
	}

	public function getAllExtra(): array
	{
		return $this->extra;
	}
}

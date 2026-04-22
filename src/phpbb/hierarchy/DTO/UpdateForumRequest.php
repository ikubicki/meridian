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

class UpdateForumRequest
{
	private array $extra = [];

	public function __construct(
		public readonly int $forumId,
		public readonly int $actorId = 0,
		public readonly ?string $name = null,
		public readonly ?ForumType $type = null,
		public readonly ?int $parentId = null,
		public readonly ?string $description = null,
		public readonly ?string $link = null,
		public readonly ?string $image = null,
		public readonly ?string $rules = null,
		public readonly ?string $rulesLink = null,
		public readonly ?string $password = null,
		public readonly ?bool $clearPassword = null,
		public readonly ?int $style = null,
		public readonly ?int $topicsPerPage = null,
		public readonly ?int $flags = null,
		public readonly ?bool $displayOnIndex = null,
		public readonly ?bool $displaySubforumList = null,
		public readonly ?bool $enableIndexing = null,
		public readonly ?bool $enableIcons = null,
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

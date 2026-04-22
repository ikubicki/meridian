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

namespace phpbb\hierarchy\Entity;

final readonly class Forum
{
	public function __construct(
		public int $id,
		public string $name,
		public string $description,
		public string $descriptionBitfield,
		public int $descriptionOptions,
		public string $descriptionUid,
		public int $parentId,
		public int $leftId,
		public int $rightId,
		public ForumType $type,
		public ForumStatus $status,
		public string $image,
		public string $rules,
		public string $rulesLink,
		public string $rulesBitfield,
		public int $rulesOptions,
		public string $rulesUid,
		public string $link,
		public string $password,
		public int $style,
		public int $topicsPerPage,
		public int $flags,
		public int $options,
		public bool $displayOnIndex,
		public bool $displaySubforumList,
		public bool $enableIndexing,
		public bool $enableIcons,
		public ForumStats $stats,
		public ForumLastPost $lastPost,
		public ForumPruneSettings $pruneSettings,
		public array $parents,
	) {
	}

	public function isLeaf(): bool
	{
		return $this->rightId - $this->leftId === 1;
	}

	public function descendantCount(): int
	{
		return (int) (($this->rightId - $this->leftId - 1) / 2);
	}

	public function isCategory(): bool
	{
		return $this->type === ForumType::Category;
	}

	public function isForum(): bool
	{
		return $this->type === ForumType::Forum;
	}

	public function isLink(): bool
	{
		return $this->type === ForumType::Link;
	}
}

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

namespace phpbb\storage\Entity;

use phpbb\storage\Enum\AssetType;
use phpbb\storage\Enum\FileVisibility;
use phpbb\storage\Enum\VariantType;

final readonly class StoredFile
{
	public function __construct(
		public string $id,
		public AssetType $assetType,
		public FileVisibility $visibility,
		public string $originalName,
		public string $physicalName,
		public string $mimeType,
		public int $filesize,
		public string $checksum,
		public bool $isOrphan,
		public ?string $parentId,
		public ?VariantType $variantType,
		public int $uploaderId,
		public int $forumId,
		public int $createdAt,
		public ?int $claimedAt,
	) {
	}

	public function isImage(): bool
	{
		return str_starts_with($this->mimeType, 'image/');
	}

	public function isVariant(): bool
	{
		return $this->parentId !== null;
	}

	public function isClaimed(): bool
	{
		return $this->claimedAt !== null;
	}
}

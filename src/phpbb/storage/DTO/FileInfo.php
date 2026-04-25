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

namespace phpbb\storage\DTO;

use phpbb\storage\Enum\AssetType;
use phpbb\storage\Enum\FileVisibility;

final readonly class FileInfo
{
	public function __construct(
		public string $fileId,
		public AssetType $assetType,
		public FileVisibility $visibility,
		public string $originalName,
		public string $url,
		public string $mimeType,
		public int $filesize,
		public bool $isOrphan,
		public int $createdAt,
		public ?int $claimedAt,
	) {
	}

	public function toArray(): array
	{
		return [
			'file_id'       => $this->fileId,
			'asset_type'    => $this->assetType->value,
			'visibility'    => $this->visibility->value,
			'original_name' => $this->originalName,
			'url'           => $this->url,
			'mime_type'     => $this->mimeType,
			'filesize'      => $this->filesize,
			'is_orphan'     => $this->isOrphan,
			'created_at'    => $this->createdAt,
			'claimed_at'    => $this->claimedAt,
		];
	}
}

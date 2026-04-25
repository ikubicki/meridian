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

final readonly class StoreFileRequest
{
	public function __construct(
		public AssetType $assetType,
		public int $uploaderId,
		public int $forumId,
		public string $tmpPath,
		public string $originalName,
		public string $mimeType,
		public int $filesize,
	) {
	}
}

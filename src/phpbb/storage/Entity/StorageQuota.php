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

final readonly class StorageQuota
{
	public function __construct(
		public int $userId,
		public int $forumId,
		public int $usedBytes,
		public int $maxBytes,
		public int $updatedAt,
	) {
	}

	public function isExceeded(int $additionalBytes): bool
	{
		return $this->usedBytes + $additionalBytes > $this->maxBytes;
	}
}

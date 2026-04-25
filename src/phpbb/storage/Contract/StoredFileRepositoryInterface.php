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

namespace phpbb\storage\Contract;

use phpbb\storage\Entity\StoredFile;

interface StoredFileRepositoryInterface
{
	public function findById(string $fileId): ?StoredFile;

	public function save(StoredFile $file): void;

	public function delete(string $fileId): void;

	/** @return StoredFile[] */
	public function findOrphansBefore(int $timestamp): array;

	public function markClaimed(string $fileId, int $claimedAt): void;

	/** @return StoredFile[] */
	public function findVariants(string $parentId): array;
}

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

use phpbb\common\Event\DomainEventCollection;
use phpbb\storage\DTO\ClaimContext;
use phpbb\storage\DTO\StoreFileRequest;
use phpbb\storage\Entity\StoredFile;

interface StorageServiceInterface
{
	public function store(StoreFileRequest $request): DomainEventCollection;

	public function retrieve(string $fileId): StoredFile;

	public function delete(string $fileId, int $actorId): DomainEventCollection;

	public function claim(ClaimContext $ctx): DomainEventCollection;

	public function getUrl(string $fileId): string;

	public function exists(string $fileId): bool;

	/**
	 * Open a read stream for the file content. Caller is responsible for closing it.
	 *
	 * @return resource
	 * @throws \phpbb\storage\Exception\FileNotFoundException
	 */
	public function readStream(string $fileId): mixed;
}

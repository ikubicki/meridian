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

namespace phpbb\storage\Service;

use Doctrine\DBAL\Connection;
use phpbb\common\Event\DomainEventCollection;
use phpbb\storage\Adapter\StorageAdapterFactory;
use phpbb\storage\Contract\OrphanServiceInterface;
use phpbb\storage\Contract\QuotaServiceInterface;
use phpbb\storage\Contract\StoredFileRepositoryInterface;
use phpbb\storage\Event\OrphanCleanupEvent;
use Psr\Log\LoggerInterface;

final class OrphanService implements OrphanServiceInterface
{
	public function __construct(
		private readonly StoredFileRepositoryInterface $fileRepo,
		private readonly StorageAdapterFactory $adapterFactory,
		private readonly QuotaServiceInterface $quotaService,
		private readonly Connection $connection,
		private readonly LoggerInterface $logger,
	) {
	}

	public function cleanupExpired(int $olderThanTimestamp): DomainEventCollection
	{
		$orphans = $this->fileRepo->findOrphansBefore($olderThanTimestamp);
		$events  = [];

		foreach ($orphans as $orphan) {
			try {
				$this->connection->beginTransaction();

				$filesystem = $this->adapterFactory->createForAssetType($orphan->assetType);

				try {
					$filesystem->delete($orphan->physicalName);
				} catch (\Throwable) {
					// Best effort — file may already be gone from disk
				}

				$this->quotaService->release($orphan->uploaderId, $orphan->forumId, $orphan->filesize);
				$this->fileRepo->delete($orphan->id);
				$this->connection->commit();

				$events[] = new OrphanCleanupEvent($orphan->id);
			} catch (\Throwable $e) {
				try {
					$this->connection->rollBack();
				} catch (\Throwable) {
					// Ignore rollback errors
				}

				$this->logger->error('Failed to clean up orphan file {id}: {error}', [
					'id'    => $orphan->id,
					'error' => $e->getMessage(),
				]);
			}
		}

		return new DomainEventCollection($events);
	}
}

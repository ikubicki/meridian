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

namespace phpbb\storage;

use Doctrine\DBAL\Connection;
use phpbb\common\Event\DomainEventCollection;
use phpbb\storage\Adapter\StorageAdapterFactory;
use phpbb\storage\Contract\OrphanServiceInterface;
use phpbb\storage\Contract\QuotaServiceInterface;
use phpbb\storage\Contract\StorageServiceInterface;
use phpbb\storage\Contract\StoredFileRepositoryInterface;
use phpbb\storage\Contract\UrlGeneratorInterface;
use phpbb\storage\DTO\ClaimContext;
use phpbb\storage\DTO\StoreFileRequest;
use phpbb\storage\Entity\StoredFile;
use phpbb\storage\Event\FileClaimedEvent;
use phpbb\storage\Event\FileDeletedEvent;
use phpbb\storage\Event\FileStoredEvent;
use phpbb\storage\Exception\FileNotFoundException;
use phpbb\storage\Exception\OrphanClaimException;
use phpbb\storage\Exception\StorageWriteException;
use phpbb\storage\Exception\UploadValidationException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class StorageService implements StorageServiceInterface
{
	public function __construct(
		private readonly StoredFileRepositoryInterface $fileRepo,
		private readonly QuotaServiceInterface $quotaService,
		private readonly OrphanServiceInterface $orphanService,
		private readonly UrlGeneratorInterface $urlGenerator,
		private readonly StorageAdapterFactory $adapterFactory,
		private readonly Connection $connection,
		private readonly EventDispatcherInterface $dispatcher,
	) {
	}

	public function store(StoreFileRequest $request): DomainEventCollection
	{
		if ($request->mimeType === '') {
			throw new UploadValidationException('MIME type must not be empty');
		}

		if ($request->filesize <= 0) {
			throw new UploadValidationException('File size must be greater than zero');
		}

		// Reserve quota BEFORE opening a DB transaction (explicit compensation on failure)
		$this->quotaService->checkAndReserve($request->uploaderId, $request->forumId, $request->filesize);

		$fileId       = $this->generateUuidV7();
		$physicalName = $fileId;
		$visibility   = $request->assetType->toVisibility();
		$checksum     = hash_file('sha256', $request->tmpPath);
		$createdAt    = time();

		$this->connection->beginTransaction();

		try {
			$filesystem = $this->adapterFactory->createForAssetType($request->assetType);
			$filesystem->write($physicalName, file_get_contents($request->tmpPath));

			$file = new StoredFile(
				id:           $fileId,
				assetType:    $request->assetType,
				visibility:   $visibility,
				originalName: $request->originalName,
				physicalName: $physicalName,
				mimeType:     $request->mimeType,
				filesize:     $request->filesize,
				checksum:     $checksum,
				isOrphan:     true,
				parentId:     null,
				variantType:  null,
				uploaderId:   $request->uploaderId,
				forumId:      $request->forumId,
				createdAt:    $createdAt,
				claimedAt:    null,
			);

			$this->fileRepo->save($file);
			$this->connection->commit();

			$event = new FileStoredEvent($fileId, $request->uploaderId, $fileId, $request->assetType);
			$this->dispatcher->dispatch($event, 'phpbb.storage.file_stored');

			return new DomainEventCollection([$event]);
		} catch (\Throwable $e) {
			$this->connection->rollBack();
			$this->quotaService->release($request->uploaderId, $request->forumId, $request->filesize);

			if ($e instanceof StorageWriteException) {
				throw $e;
			}

			throw new StorageWriteException('Failed to store file: ' . $e->getMessage(), previous: $e);
		}
	}

	public function retrieve(string $fileId): StoredFile
	{
		return $this->fileRepo->findById($fileId)
			?? throw new FileNotFoundException('File not found: ' . $fileId);
	}

	public function delete(string $fileId, int $actorId): DomainEventCollection
	{
		$file = $this->retrieve($fileId);

		$this->connection->beginTransaction();

		try {
			$filesystem = $this->adapterFactory->createForAssetType($file->assetType);

			try {
				$filesystem->delete($file->physicalName);
			} catch (\Throwable) {
				// Best effort — file may already be absent from disk
			}

			$this->quotaService->release($file->uploaderId, $file->forumId, $file->filesize);

			$variants = $this->fileRepo->findVariants($fileId);
			foreach ($variants as $variant) {
				try {
					$filesystem->delete($variant->physicalName);
				} catch (\Throwable) {
					// Best effort
				}
				$this->fileRepo->delete($variant->id);
			}

			$this->fileRepo->delete($fileId);
			$this->connection->commit();

			$event = new FileDeletedEvent($fileId, $actorId);
			$this->dispatcher->dispatch($event);

			return new DomainEventCollection([$event]);
		} catch (\Throwable $e) {
			$this->connection->rollBack();

			throw $e;
		}
	}

	public function claim(ClaimContext $ctx): DomainEventCollection
	{
		$file = $this->retrieve($ctx->fileId);

		if (!$file->isOrphan) {
			throw new OrphanClaimException('File ' . $ctx->fileId . ' is already claimed');
		}

		$this->fileRepo->markClaimed($ctx->fileId, time());

		$event = new FileClaimedEvent($ctx->fileId, $ctx->actorId);
		$this->dispatcher->dispatch($event);

		return new DomainEventCollection([$event]);
	}

	public function getUrl(string $fileId): string
	{
		return $this->urlGenerator->generateUrl($this->retrieve($fileId));
	}

	public function exists(string $fileId): bool
	{
		return $this->fileRepo->findById($fileId) !== null;
	}

	private function generateUuidV7(): string
	{
		$ms    = (int) (microtime(true) * 1000);
		$bytes = str_pad('', 16, "\0");

		for ($i = 5; $i >= 0; $i--) {
			$bytes[$i] = chr($ms & 0xFF);
			$ms      >>= 8;
		}

		$rand  = random_bytes(10);
		$bytes = substr($bytes, 0, 6) . $rand;

		// Set version = 7
		$bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70);

		// Set variant = 10xxxxxx
		$bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

		return bin2hex($bytes);
	}
}

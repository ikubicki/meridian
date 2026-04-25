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

namespace phpbb\storage\Variant;

use phpbb\storage\Adapter\StorageAdapterFactory;
use phpbb\storage\Contract\StoredFileRepositoryInterface;
use phpbb\storage\Entity\StoredFile;
use phpbb\storage\Enum\AssetType;
use phpbb\storage\Enum\VariantType;
use phpbb\storage\Event\FileStoredEvent;
use phpbb\storage\Event\VariantGeneratedEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class ThumbnailListener
{
	public function __construct(
		private readonly VariantGeneratorInterface $generator,
		private readonly StoredFileRepositoryInterface $fileRepo,
		private readonly StorageAdapterFactory $adapterFactory,
		private readonly EventDispatcherInterface $dispatcher,
	) {
	}

	public function onFileStored(FileStoredEvent $event): void
	{
		// Only process image-compatible asset types
		if ($event->assetType !== AssetType::Avatar) {
			return;
		}

		try {
			$file = $this->fileRepo->findById($event->fileId);
			if ($file === null || !$file->isImage()) {
				return;
			}

			$filesystem = $this->adapterFactory->createForAssetType($file->assetType);
			$content    = $filesystem->read($file->physicalName);
			$thumbData  = $this->generator->generate($content);

			$thumbId = $this->generateUuidV7();
			$filesystem->write($thumbId, $thumbData);

			$thumb = new StoredFile(
				id:           $thumbId,
				assetType:    $file->assetType,
				visibility:   $file->visibility,
				originalName: 'thumb_' . $file->originalName,
				physicalName: $thumbId,
				mimeType:     'image/jpeg',
				filesize:     strlen($thumbData),
				checksum:     hash('sha256', $thumbData),
				isOrphan:     $file->isOrphan,
				parentId:     $file->id,
				variantType:  VariantType::Thumbnail,
				uploaderId:   $file->uploaderId,
				forumId:      $file->forumId,
				createdAt:    time(),
				claimedAt:    null,
			);

			$this->fileRepo->save($thumb);

			$this->dispatcher->dispatch(new VariantGeneratedEvent($thumbId, 0, $file->id));
		} catch (\Throwable) {
			// Error-isolated: thumbnail failure never propagates to the upload response
		}
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
		$bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70);
		$bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

		return bin2hex($bytes);
	}
}

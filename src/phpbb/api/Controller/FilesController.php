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

namespace phpbb\api\Controller;

use phpbb\storage\Contract\StorageServiceInterface;
use phpbb\storage\DTO\FileStoredResponse;
use phpbb\storage\DTO\StoreFileRequest;
use phpbb\storage\Enum\AssetType;
use phpbb\storage\Exception\QuotaExceededException;
use phpbb\storage\Exception\UploadValidationException;
use phpbb\user\Entity\User;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class FilesController
{
	private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB

	public function __construct(
		private readonly StorageServiceInterface $storageService,
		private readonly EventDispatcherInterface $dispatcher,
	) {
	}

	#[Route('/files', name: 'api_v1_files_upload', methods: ['POST'])]
	public function upload(Request $request): JsonResponse
	{
		/** @var User|null $user */
		$user = $request->attributes->get('_api_user');

		if ($user === null) {
			return new JsonResponse(['error' => 'Authentication required'], 401);
		}

		$uploadedFile = $request->files->get('file');
		if ($uploadedFile === null) {
			return new JsonResponse(['error' => 'File is required', 'code' => 'missing_file'], 400);
		}

		if ($uploadedFile->getSize() > self::MAX_FILE_SIZE) {
			return new JsonResponse(['error' => 'File too large'], 413);
		}

		if ($uploadedFile->getSize() === 0) {
			return new JsonResponse(['error' => 'File must not be empty', 'code' => 'empty_file'], 400);
		}

		$assetTypeValue = $request->request->get('asset_type', '');
		$assetType = AssetType::tryFrom($assetTypeValue);
		if ($assetType === null) {
			return new JsonResponse([
				'error' => 'Invalid asset_type. Must be one of: attachment, avatar, export',
				'code'  => 'invalid_asset_type',
			], 400);
		}

		$forumId = (int) $request->request->get('forum_id', 0);

		// Server-side MIME detection — ignore client Content-Type
		$tmpPath  = $uploadedFile->getRealPath();
		$finfo    = new \finfo(FILEINFO_MIME_TYPE);
		$mimeType = $finfo->file($tmpPath);

		if ($mimeType === false || $mimeType === '') {
			return new JsonResponse(['error' => 'Could not detect file MIME type', 'code' => 'mime_detection_failed'], 400);
		}

		try {
			$storeRequest = new StoreFileRequest(
				assetType:    $assetType,
				uploaderId:   $user->id,
				forumId:      $forumId,
				tmpPath:      $tmpPath,
				originalName: $uploadedFile->getClientOriginalName() ?? 'upload',
				mimeType:     $mimeType,
				filesize:     $uploadedFile->getSize(),
			);

			$events = $this->storageService->store($storeRequest);
			$events->dispatch($this->dispatcher);

			// Retrieve the stored file to build the response
			$fileEvent = $events->first();
			$fileId    = $fileEvent?->entityId ?? '';
			$url       = $this->storageService->getUrl((string) $fileId);

			$response = new FileStoredResponse(
				fileId:   (string) $fileId,
				url:      $url,
				mimeType: $mimeType,
				filesize: $uploadedFile->getSize(),
			);

			return new JsonResponse($response->toArray(), 201);
		} catch (QuotaExceededException $e) {
			return new JsonResponse(['error' => $e->getMessage(), 'code' => 'quota_exceeded'], 400);
		} catch (UploadValidationException $e) {
			return new JsonResponse(['error' => $e->getMessage(), 'code' => 'validation_error'], 400);
		} catch (\Throwable) {
			return new JsonResponse(['error' => 'Storage write failed'], 500);
		}
	}
}

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

use phpbb\storage\Contract\UrlGeneratorInterface;
use phpbb\storage\Entity\StoredFile;
use phpbb\storage\Enum\AssetType;
use phpbb\storage\Enum\FileVisibility;

final class UrlGenerator implements UrlGeneratorInterface
{
	public function __construct(
		private readonly string $baseUrl,
	) {
	}

	public function generateUrl(StoredFile $file): string
	{
		if ($file->visibility === FileVisibility::Public) {
			return $this->generatePublicUrl($file->physicalName, $file->assetType);
		}

		return $this->generatePrivateUrl($file->id);
	}

	public function generatePublicUrl(string $physicalName, AssetType $assetType): string
	{
		$base = rtrim($this->baseUrl, '/');

		return match ($assetType) {
			AssetType::Avatar => $base . '/images/avatars/upload/' . $physicalName,
			default           => $base . '/files/' . $physicalName,
		};
	}

	public function generatePrivateUrl(string $fileId): string
	{
		return rtrim($this->baseUrl, '/') . '/api/v1/files/' . $fileId . '/download';
	}
}

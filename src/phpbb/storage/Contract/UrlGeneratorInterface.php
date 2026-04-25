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
use phpbb\storage\Enum\AssetType;

interface UrlGeneratorInterface
{
	public function generateUrl(StoredFile $file): string;

	/**
	 * Avatar:     {baseUrl}/images/avatars/upload/{physicalName}
	 * Attachment/Export: {baseUrl}/files/{physicalName}
	 */
	public function generatePublicUrl(string $physicalName, AssetType $assetType): string;

	/**
	 * Private auth URL: {baseUrl}/api/v1/files/{fileId}/download
	 */
	public function generatePrivateUrl(string $fileId): string;
}

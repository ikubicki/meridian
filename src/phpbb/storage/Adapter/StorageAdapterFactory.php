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

namespace phpbb\storage\Adapter;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use phpbb\storage\Enum\AssetType;

final class StorageAdapterFactory
{
	public function __construct(
		private readonly string $storagePath,
	) {
	}

	public function createForAssetType(AssetType $assetType): FilesystemOperator
	{
		$subPath = rtrim($this->storagePath, '/') . '/' . $assetType->storagePath();

		return new Filesystem(new LocalFilesystemAdapter($subPath));
	}
}

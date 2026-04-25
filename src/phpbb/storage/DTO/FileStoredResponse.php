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

namespace phpbb\storage\DTO;

final readonly class FileStoredResponse
{
	public function __construct(
		public string $fileId,
		public string $url,
		public string $mimeType,
		public int $filesize,
	) {
	}

	public function toArray(): array
	{
		return [
			'file_id'   => $this->fileId,
			'url'       => $this->url,
			'mime_type' => $this->mimeType,
			'filesize'  => $this->filesize,
		];
	}
}

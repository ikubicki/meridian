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

final readonly class FileDeletedResponse
{
	public function __construct(
		public string $fileId,
		public int $actorId,
		public int $deletedAt,
	) {
	}

	public function toArray(): array
	{
		return [
			'file_id'    => $this->fileId,
			'actor_id'   => $this->actorId,
			'deleted_at' => $this->deletedAt,
		];
	}
}

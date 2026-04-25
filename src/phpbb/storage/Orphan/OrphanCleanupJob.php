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

namespace phpbb\storage\Orphan;

use phpbb\storage\Contract\OrphanServiceInterface;

final class OrphanCleanupJob
{
	/** Files older than this many seconds are eligible for cleanup. */
	private const TTL_SECONDS = 86400;

	public function __construct(
		private readonly OrphanServiceInterface $orphanService,
	) {
	}

	public function run(): void
	{
		$threshold = time() - self::TTL_SECONDS;
		$this->orphanService->cleanupExpired($threshold);
	}
}

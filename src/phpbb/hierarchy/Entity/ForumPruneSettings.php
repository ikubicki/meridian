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

namespace phpbb\hierarchy\Entity;

final readonly class ForumPruneSettings
{
	public function __construct(
		public bool $enabled,
		public int $days,
		public int $viewed,
		public int $frequency,
		public int $next,
	) {
	}
}

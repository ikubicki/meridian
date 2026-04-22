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

namespace phpbb\user\DTO;

/**
 * Lightweight user representation used in post lists, member search,
 * and anywhere a full User entity is unnecessary.
 */
final readonly class UserDisplayDTO
{
	public function __construct(
		public int $id,
		public string $username,
		public string $colour,
		public string $avatarUrl,
	) {
	}
}

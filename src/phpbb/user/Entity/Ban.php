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

namespace phpbb\user\Entity;

use phpbb\user\Enum\BanType;

/**
 * Ban record — maps to phpbb_banlist.
 *
 * Exactly one of userId / ip / email is meaningful depending on the ban type.
 */
final readonly class Ban
{
	public function __construct(
		public int $id,
		public BanType $banType,
		public int $userId,
		public string $ip,
		public string $email,
		public \DateTimeImmutable $start,
		public ?\DateTimeImmutable $end,
		public bool $exclude,
		public string $reason,
		public string $displayReason,
	) {
	}
}

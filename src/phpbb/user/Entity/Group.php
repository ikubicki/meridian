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

use phpbb\user\Enum\GroupType;

/**
 * phpBB group — maps to phpbb_groups.
 */
final readonly class Group
{
	public function __construct(
		public int $id,
		public GroupType $type,
		public string $name,
		public string $description,
		public bool $displayOnIndex,
		public bool $legend,
		public string $colour,
		public int $rank,
		public string $avatar,
		public bool $receivePm,
		public int $messageLimit,
		public int $maxRecipients,
		public bool $founderManage,
		public bool $skipAuth,
		public int $teamPage,
	) {
	}
}

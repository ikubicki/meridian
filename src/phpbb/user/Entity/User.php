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

use phpbb\user\Enum\InactiveReason;
use phpbb\user\Enum\UserType;

/**
 * Core user aggregate root — maps to phpbb_users.
 *
 * All fields correspond to the phpBB4 schema column names (camelCase-mapped).
 * Use the repository to persist changes; this class is immutable.
 */
final readonly class User
{
	public function __construct(
		public int $id,
		public UserType $type,
		public string $username,
		public string $usernameClean,
		public string $email,
		public string $passwordHash,
		public string $colour,
		public int $defaultGroupId,
		public string $avatarUrl,
		public \DateTimeImmutable $registeredAt,
		public \DateTimeImmutable $lastmark,
		public int $posts,
		public ?\DateTimeImmutable $lastPostTime,
		public bool $isNew,
		public int $rank,
		public string $registrationIp,
		public int $loginAttempts,
		public ?InactiveReason $inactiveReason,
		public string $formSalt,
		public string $activationKey,
		public int $tokenGeneration = 0,
		public int $permVersion = 0,
	) {
	}
}

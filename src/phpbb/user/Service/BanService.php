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

namespace phpbb\user\Service;

use phpbb\user\Contract\BanRepositoryInterface;
use phpbb\user\Exception\BannedException;

/**
 * Provides ban-check helpers used at authentication and registration time.
 */
class BanService
{
	public function __construct(
		private readonly BanRepositoryInterface $banRepository,
	) {
	}

	public function isUserBanned(int $userId): bool
	{
		return $this->banRepository->isUserBanned($userId);
	}

	public function isIpBanned(string $ip): bool
	{
		return $this->banRepository->isIpBanned($ip);
	}

	public function isEmailBanned(string $email): bool
	{
		return $this->banRepository->isEmailBanned($email);
	}

	/**
	 * Asserts that none of the given identifiers are banned.
	 *
	 * @throws BannedException if any identifier is banned
	 */
	public function assertNotBanned(int $userId, string $ip, string $email): void
	{
		if ($this->banRepository->isUserBanned($userId)) {
			throw new BannedException('User account is banned.');
		}

		if ($this->banRepository->isIpBanned($ip)) {
			throw new BannedException('IP address is banned.');
		}

		if ($this->banRepository->isEmailBanned($email)) {
			throw new BannedException('Email address is banned.');
		}
	}
}

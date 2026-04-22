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

namespace phpbb\auth\Contract;

use phpbb\auth\Exception\AuthenticationFailedException;
use phpbb\auth\Exception\InvalidRefreshTokenException;
use phpbb\user\Exception\BannedException;

interface AuthenticationServiceInterface
{
	/**
	 * Authenticates credentials and returns access + refresh token pair.
	 *
	 * @throws AuthenticationFailedException
	 * @throws BannedException
	 */
	public function login(string $username, string $password, string $ip): array;

	/**
	 * Revokes all refresh tokens for the given user.
	 */
	public function logout(int $userId): void;

	/**
	 * Exchanges a raw refresh token for a new access + refresh token pair.
	 *
	 * @throws InvalidRefreshTokenException
	 */
	public function refresh(string $rawRefreshToken): array;

	/**
	 * Issues an elevated-privilege token after password re-verification.
	 *
	 * @throws AuthenticationFailedException
	 */
	public function elevate(int $userId, string $password): array;
}

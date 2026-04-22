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

use phpbb\auth\Entity\TokenPayload;
use phpbb\user\Entity\User;

interface TokenServiceInterface
{
	/**
	 * Issues a standard-privilege JWT access token for the given user.
	 */
	public function issueAccessToken(User $user): string;

	/**
	 * Issues an elevated-privilege JWT access token for the given user.
	 */
	public function issueElevatedToken(User $user): string;

	/**
	 * Decodes and validates a raw JWT, returning the structured payload.
	 *
	 * @throws \UnexpectedValueException
	 */
	public function decodeToken(string $rawToken, string $expectedAud): TokenPayload;
}

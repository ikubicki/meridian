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

use phpbb\user\Contract\PasswordServiceInterface;

final class PasswordService implements PasswordServiceInterface
{
	public function hashPassword(string $plaintext): string
	{
		return password_hash($plaintext, PASSWORD_ARGON2ID);
	}

	public function verifyPassword(string $plaintext, string $hash): bool
	{
		return password_verify($plaintext, $hash);
	}

	public function needsRehash(string $hash): bool
	{
		return password_needs_rehash($hash, PASSWORD_ARGON2ID);
	}
}

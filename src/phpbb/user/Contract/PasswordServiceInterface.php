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

namespace phpbb\user\Contract;

interface PasswordServiceInterface
{
	public function hashPassword(string $plaintext): string;

	public function verifyPassword(string $plaintext, string $hash): bool;

	public function needsRehash(string $hash): bool;
}

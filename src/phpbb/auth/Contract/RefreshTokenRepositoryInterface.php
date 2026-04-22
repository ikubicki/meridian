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

use phpbb\auth\Entity\RefreshToken;

interface RefreshTokenRepositoryInterface
{
	/**
	 * Persists a refresh token record.
	 */
	public function save(RefreshToken $token): void;

	/**
	 * Finds a token by its SHA-256 hash.
	 *
	 * Returns the entity even if revoked — caller checks isRevoked() for theft detection.
	 */
	public function findByHash(string $hash): ?RefreshToken;

	/**
	 * Marks the token with the given hash as revoked.
	 */
	public function revokeByHash(string $hash): void;

	/**
	 * Revokes all tokens belonging to the given family — used on theft detection.
	 */
	public function revokeFamily(string $familyId): void;

	/**
	 * Revokes all tokens issued for the given user.
	 */
	public function revokeAllForUser(int $userId): void;

	/**
	 * Deletes tokens whose expiry time has passed.
	 */
	public function deleteExpired(): void;
}

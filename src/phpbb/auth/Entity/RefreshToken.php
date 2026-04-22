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

namespace phpbb\auth\Entity;

final readonly class RefreshToken
{
	public function __construct(
		public int $id,
		public int $userId,
		public string $familyId,
		public string $tokenHash,
		public \DateTimeImmutable $issuedAt,
		public \DateTimeImmutable $expiresAt,
		public ?\DateTimeImmutable $revokedAt,
	) {
	}

	/**
	 * Returns true if the token has been explicitly revoked.
	 */
	public function isRevoked(): bool
	{
		return $this->revokedAt !== null;
	}

	/**
	 * Returns true if the token's expiry time has passed.
	 */
	public function isExpired(): bool
	{
		return $this->expiresAt < new \DateTimeImmutable();
	}

	/**
	 * Returns true only when the token is neither revoked nor expired.
	 */
	public function isValid(): bool
	{
		return !$this->isRevoked() && !$this->isExpired();
	}
}

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

namespace phpbb\auth\Repository;

use phpbb\auth\Contract\RefreshTokenRepositoryInterface;
use phpbb\auth\Entity\RefreshToken;

final class PdoRefreshTokenRepository implements RefreshTokenRepositoryInterface
{
	private const TABLE = 'phpbb_auth_refresh_tokens';

	public function __construct(
		private readonly \PDO $pdo,
	) {}

	public function save(RefreshToken $token): void
	{
		$sql  = 'INSERT INTO ' . self::TABLE . ' (user_id, family_id, token_hash, issued_at, expires_at, revoked_at)'
			. ' VALUES (:userId, :familyId, :tokenHash, :issuedAt, :expiresAt, :revokedAt)';
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute([
			':userId'    => $token->userId,
			':familyId'  => $token->familyId,
			':tokenHash' => $token->tokenHash,
			':issuedAt'  => $token->issuedAt->getTimestamp(),
			':expiresAt' => $token->expiresAt->getTimestamp(),
			':revokedAt' => $token->revokedAt?->getTimestamp(),
		]);
	}

	public function findByHash(string $hash): ?RefreshToken
	{
		$sql  = 'SELECT * FROM ' . self::TABLE . ' WHERE token_hash = :hash LIMIT 1';
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute([':hash' => $hash]);
		$row = $stmt->fetch();

		if ($row === false)
		{
			return null;
		}

		return $this->hydrate($row);
	}

	public function revokeByHash(string $hash): void
	{
		$sql  = 'UPDATE ' . self::TABLE . ' SET revoked_at = :now WHERE token_hash = :hash AND revoked_at IS NULL';
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute([
			':now'  => time(),
			':hash' => $hash,
		]);
	}

	public function revokeFamily(string $familyId): void
	{
		$sql  = 'UPDATE ' . self::TABLE . ' SET revoked_at = :now WHERE family_id = :familyId AND revoked_at IS NULL';
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute([
			':now'      => time(),
			':familyId' => $familyId,
		]);
	}

	public function revokeAllForUser(int $userId): void
	{
		$sql  = 'UPDATE ' . self::TABLE . ' SET revoked_at = :now WHERE user_id = :userId AND revoked_at IS NULL';
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute([
			':now'    => time(),
			':userId' => $userId,
		]);
	}

	public function deleteExpired(): void
	{
		$sql  = 'DELETE FROM ' . self::TABLE . ' WHERE expires_at < :now AND revoked_at IS NOT NULL';
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute([':now' => time()]);
	}

	private function hydrate(array $row): RefreshToken
	{
		return new RefreshToken(
			id:        (int) $row['id'],
			userId:    (int) $row['user_id'],
			familyId:  (string) $row['family_id'],
			tokenHash: (string) $row['token_hash'],
			issuedAt:  \DateTimeImmutable::createFromFormat('U', (string) $row['issued_at']),
			expiresAt: \DateTimeImmutable::createFromFormat('U', (string) $row['expires_at']),
			revokedAt: $row['revoked_at'] !== null
				? \DateTimeImmutable::createFromFormat('U', (string) $row['revoked_at'])
				: null,
		);
	}
}

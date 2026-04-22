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
use phpbb\db\Exception\RepositoryException;

final class DbalRefreshTokenRepository implements RefreshTokenRepositoryInterface
{
	private const TABLE = 'phpbb_auth_refresh_tokens';

	public function __construct(
		private readonly \Doctrine\DBAL\Connection $connection,
	) {
	}

	public function save(RefreshToken $token): void
	{
		try {
			$sql = 'INSERT INTO ' . self::TABLE . ' (user_id, family_id, token_hash, issued_at, expires_at, revoked_at)'
				. ' VALUES (:userId, :familyId, :tokenHash, :issuedAt, :expiresAt, :revokedAt)';

			$this->connection->executeStatement($sql, [
				'userId'    => $token->userId,
				'familyId'  => $token->familyId,
				'tokenHash' => $token->tokenHash,
				'issuedAt'  => $token->issuedAt->getTimestamp(),
				'expiresAt' => $token->expiresAt->getTimestamp(),
				'revokedAt' => $token->revokedAt?->getTimestamp(),
			]);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to save refresh token.', previous: $e);
		}
	}

	public function findByHash(string $hash): ?RefreshToken
	{
		try {
			$sql = 'SELECT * FROM ' . self::TABLE . ' WHERE token_hash = :hash LIMIT 1';
			$row = $this->connection->executeQuery($sql, ['hash' => $hash])->fetchAssociative();

			if ($row === false) {
				return null;
			}

			return $this->hydrate($row);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find refresh token by hash.', previous: $e);
		}
	}

	public function revokeByHash(string $hash): void
	{
		try {
			$sql = 'UPDATE ' . self::TABLE . ' SET revoked_at = :now WHERE token_hash = :hash AND revoked_at IS NULL';
			$this->connection->executeStatement($sql, [
				'now'  => time(),
				'hash' => $hash,
			]);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to revoke refresh token by hash.', previous: $e);
		}
	}

	public function revokeFamily(string $familyId): void
	{
		try {
			$sql = 'UPDATE ' . self::TABLE . ' SET revoked_at = :now WHERE family_id = :familyId AND revoked_at IS NULL';
			$this->connection->executeStatement($sql, [
				'now'      => time(),
				'familyId' => $familyId,
			]);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to revoke refresh token family.', previous: $e);
		}
	}

	public function revokeAllForUser(int $userId): void
	{
		try {
			$sql = 'UPDATE ' . self::TABLE . ' SET revoked_at = :now WHERE user_id = :userId AND revoked_at IS NULL';
			$this->connection->executeStatement($sql, [
				'now'    => time(),
				'userId' => $userId,
			]);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to revoke all refresh tokens for user.', previous: $e);
		}
	}

	public function deleteExpired(): void
	{
		try {
			$sql = 'DELETE FROM ' . self::TABLE . ' WHERE expires_at < :now AND revoked_at IS NOT NULL';
			$this->connection->executeStatement($sql, [
				'now' => time(),
			]);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to delete expired refresh tokens.', previous: $e);
		}
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

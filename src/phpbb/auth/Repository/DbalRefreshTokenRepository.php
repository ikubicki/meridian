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
			$qb = $this->connection->createQueryBuilder();
			$qb->insert(self::TABLE)
				->values([
					'user_id'    => ':userId',
					'family_id'  => ':familyId',
					'token_hash' => ':tokenHash',
					'issued_at'  => ':issuedAt',
					'expires_at' => ':expiresAt',
					'revoked_at' => ':revokedAt',
				])
				->setParameter('userId', $token->userId)
				->setParameter('familyId', $token->familyId)
				->setParameter('tokenHash', $token->tokenHash)
				->setParameter('issuedAt', $token->issuedAt->getTimestamp())
				->setParameter('expiresAt', $token->expiresAt->getTimestamp())
				->setParameter('revokedAt', $token->revokedAt?->getTimestamp())
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to save refresh token.', previous: $e);
		}
	}

	public function findByHash(string $hash): ?RefreshToken
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$row = $qb->select('*')
				->from(self::TABLE)
				->where($qb->expr()->eq('token_hash', ':hash'))
				->setParameter('hash', $hash)
				->setMaxResults(1)
				->executeQuery()
				->fetchAssociative();

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
			$qb = $this->connection->createQueryBuilder();
			$qb->update(self::TABLE)
				->set('revoked_at', ':now')
				->where($qb->expr()->eq('token_hash', ':hash'))
				->andWhere($qb->expr()->isNull('revoked_at'))
				->setParameter('now', time())
				->setParameter('hash', $hash)
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to revoke refresh token by hash.', previous: $e);
		}
	}

	public function revokeFamily(string $familyId): void
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$qb->update(self::TABLE)
				->set('revoked_at', ':now')
				->where($qb->expr()->eq('family_id', ':familyId'))
				->andWhere($qb->expr()->isNull('revoked_at'))
				->setParameter('now', time())
				->setParameter('familyId', $familyId)
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to revoke refresh token family.', previous: $e);
		}
	}

	public function revokeAllForUser(int $userId): void
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$qb->update(self::TABLE)
				->set('revoked_at', ':now')
				->where($qb->expr()->eq('user_id', ':userId'))
				->andWhere($qb->expr()->isNull('revoked_at'))
				->setParameter('now', time())
				->setParameter('userId', $userId)
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to revoke all refresh tokens for user.', previous: $e);
		}
	}

	public function deleteExpired(): void
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$qb->delete(self::TABLE)
				->where($qb->expr()->lt('expires_at', ':now'))
				->andWhere($qb->expr()->isNotNull('revoked_at'))
				->setParameter('now', time())
				->executeStatement();
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

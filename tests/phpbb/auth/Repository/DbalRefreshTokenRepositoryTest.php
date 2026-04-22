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

namespace phpbb\Tests\auth\Repository;

use phpbb\auth\Entity\RefreshToken;
use phpbb\auth\Repository\DbalRefreshTokenRepository;
use phpbb\db\Exception\RepositoryException;
use phpbb\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;

final class DbalRefreshTokenRepositoryTest extends IntegrationTestCase
{
	private DbalRefreshTokenRepository $repository;

	protected function setUpSchema(): void
	{
		$this->connection->executeStatement('
			CREATE TABLE phpbb_auth_refresh_tokens (
				id         INTEGER PRIMARY KEY AUTOINCREMENT,
				user_id    INTEGER NOT NULL,
				family_id  TEXT    NOT NULL,
				token_hash TEXT    NOT NULL UNIQUE,
				issued_at  INTEGER NOT NULL,
				expires_at INTEGER NOT NULL,
				revoked_at INTEGER DEFAULT NULL
			)
		');

		$this->repository = new DbalRefreshTokenRepository($this->connection);
	}

	private function makeToken(
		string $hash = 'hash_abc',
		int $userId = 1,
		string $familyId = 'family_1',
		int $issuedAt = 1000000,
		int $expiresAt = 9999999,
		?int $revokedAt = null,
	): RefreshToken {
		return new RefreshToken(
			id: 0,
			userId: $userId,
			familyId: $familyId,
			tokenHash: $hash,
			issuedAt: \DateTimeImmutable::createFromFormat('U', (string) $issuedAt),
			expiresAt: \DateTimeImmutable::createFromFormat('U', (string) $expiresAt),
			revokedAt: $revokedAt !== null
				? \DateTimeImmutable::createFromFormat('U', (string) $revokedAt)
				: null,
		);
	}

	#[Test]
	public function testSaveAndFindByHash_returnsToken(): void
	{
		$token = $this->makeToken(hash: 'hash_001', userId: 42, familyId: 'fam_x');
		$this->repository->save($token);

		$found = $this->repository->findByHash('hash_001');

		$this->assertNotNull($found);
		$this->assertSame(42, $found->userId);
		$this->assertSame('fam_x', $found->familyId);
		$this->assertSame('hash_001', $found->tokenHash);
		$this->assertSame(1000000, $found->issuedAt->getTimestamp());
		$this->assertSame(9999999, $found->expiresAt->getTimestamp());
	}

	#[Test]
	public function testFindByHash_notFound_returnsNull(): void
	{
		$result = $this->repository->findByHash('no_such_hash');

		$this->assertNull($result);
	}

	#[Test]
	public function testRevokeByHash_setsRevokedAt(): void
	{
		$token = $this->makeToken(hash: 'hash_revoke');
		$this->repository->save($token);

		$this->repository->revokeByHash('hash_revoke');

		$found = $this->repository->findByHash('hash_revoke');
		$this->assertNotNull($found);
		$this->assertNotNull($found->revokedAt);
	}

	#[Test]
	public function testRevokeFamily_revokesAllInFamily(): void
	{
		$this->repository->save($this->makeToken(hash: 'hash_f1', familyId: 'fam_abc'));
		$this->repository->save($this->makeToken(hash: 'hash_f2', familyId: 'fam_abc'));

		$this->repository->revokeFamily('fam_abc');

		$token1 = $this->repository->findByHash('hash_f1');
		$token2 = $this->repository->findByHash('hash_f2');
		$this->assertNotNull($token1?->revokedAt);
		$this->assertNotNull($token2?->revokedAt);
	}

	#[Test]
	public function testRevokeAllForUser_revokesAllUserTokens(): void
	{
		$this->repository->save($this->makeToken(hash: 'hash_u1a', userId: 1));
		$this->repository->save($this->makeToken(hash: 'hash_u1b', userId: 1));
		$this->repository->save($this->makeToken(hash: 'hash_u2a', userId: 2));

		$this->repository->revokeAllForUser(1);

		$this->assertNotNull($this->repository->findByHash('hash_u1a')?->revokedAt);
		$this->assertNotNull($this->repository->findByHash('hash_u1b')?->revokedAt);
		$this->assertNull($this->repository->findByHash('hash_u2a')?->revokedAt);
	}

	#[Test]
	public function testDeleteExpired_removesTokensPastExpiry(): void
	{
		$expired = $this->makeToken(hash: 'hash_expired', expiresAt: 100, revokedAt: 200);
		$active  = $this->makeToken(hash: 'hash_active', expiresAt: 9999999);
		$this->repository->save($expired);
		$this->repository->save($active);

		$this->repository->deleteExpired();

		$this->assertNull($this->repository->findByHash('hash_expired'));
		$this->assertNotNull($this->repository->findByHash('hash_active'));
	}

	#[Test]
	public function testSave_duplicateHash_throwsRepositoryException(): void
	{
		$token = $this->makeToken(hash: 'hash_dup');
		$this->repository->save($token);

		$this->expectException(RepositoryException::class);
		$this->repository->save($token);
	}

	#[Test]
	public function testRevokedAt_nullable_onFreshToken(): void
	{
		$token = $this->makeToken(hash: 'hash_fresh', revokedAt: null);
		$this->repository->save($token);

		$found = $this->repository->findByHash('hash_fresh');
		$this->assertNotNull($found);
		$this->assertNull($found->revokedAt);
	}
}

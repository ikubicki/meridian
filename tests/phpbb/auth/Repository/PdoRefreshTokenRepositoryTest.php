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

namespace phpbb\tests\auth\Repository;

use phpbb\auth\Entity\RefreshToken;
use phpbb\auth\Repository\PdoRefreshTokenRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PdoRefreshTokenRepositoryTest extends TestCase
{
	#[Test]
	public function itSavesTokenWithPreparedStatement(): void
	{
		$pdo  = $this->createMock(\PDO::class);
		$stmt = $this->createMock(\PDOStatement::class);

		$pdo->expects($this->once())
			->method('prepare')
			->with($this->stringContains('INSERT INTO'))
			->willReturn($stmt);

		$stmt->expects($this->once())
			->method('execute')
			->with($this->callback(function (array $params): bool {
				return $params[':tokenHash'] === 'abc123hash'
					&& $params[':userId'] === 5
					&& $params[':familyId'] === 'family-uuid'
					&& is_int($params[':issuedAt'])
					&& is_int($params[':expiresAt'])
					&& $params[':revokedAt'] === null;
			}))
			->willReturn(true);

		$now   = new \DateTimeImmutable();
		$token = new RefreshToken(
			id:        0,
			userId:    5,
			familyId:  'family-uuid',
			tokenHash: 'abc123hash',
			issuedAt:  $now,
			expiresAt: $now->modify('+30 days'),
			revokedAt: null,
		);

		$repo = new PdoRefreshTokenRepository($pdo);
		$repo->save($token);
	}

	#[Test]
	public function itFindsByHashReturnsRefreshToken(): void
	{
		$pdo  = $this->createMock(\PDO::class);
		$stmt = $this->createMock(\PDOStatement::class);

		$issuedAt  = (new \DateTimeImmutable('2024-01-01 00:00:00'))->getTimestamp();
		$expiresAt = (new \DateTimeImmutable('2024-01-31 00:00:00'))->getTimestamp();

		$row = [
			'id'         => '7',
			'user_id'    => '42',
			'family_id'  => 'fam-abc',
			'token_hash' => 'myhash',
			'issued_at'  => $issuedAt,
			'expires_at' => $expiresAt,
			'revoked_at' => null,
		];

		$pdo->method('prepare')->willReturn($stmt);
		$stmt->method('execute')->willReturn(true);
		$stmt->method('fetch')->willReturn($row);

		$repo   = new PdoRefreshTokenRepository($pdo);
		$result = $repo->findByHash('myhash');

		$this->assertInstanceOf(RefreshToken::class, $result);
		$this->assertSame('myhash', $result->tokenHash);
		$this->assertSame(42, $result->userId);
		$this->assertSame(7, $result->id);
		$this->assertNull($result->revokedAt);
	}

	#[Test]
	public function itFindsByHashReturnsNullWhenNotFound(): void
	{
		$pdo  = $this->createMock(\PDO::class);
		$stmt = $this->createMock(\PDOStatement::class);

		$pdo->method('prepare')->willReturn($stmt);
		$stmt->method('execute')->willReturn(true);
		$stmt->method('fetch')->willReturn(false);

		$repo   = new PdoRefreshTokenRepository($pdo);
		$result = $repo->findByHash('nonexistent');

		$this->assertNull($result);
	}

	#[Test]
	public function itRevokesAllForUserUpdatesWithPreparedStatement(): void
	{
		$pdo  = $this->createMock(\PDO::class);
		$stmt = $this->createMock(\PDOStatement::class);

		$pdo->expects($this->once())
			->method('prepare')
			->with($this->stringContains('user_id'))
			->willReturn($stmt);

		$stmt->expects($this->once())
			->method('execute')
			->with($this->callback(function (array $params): bool {
				return $params[':userId'] === 99;
			}))
			->willReturn(true);

		$repo = new PdoRefreshTokenRepository($pdo);
		$repo->revokeAllForUser(99);
	}
}

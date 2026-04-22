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

namespace phpbb\Tests\auth\Entity;

use phpbb\auth\Entity\RefreshToken;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RefreshTokenTest extends TestCase
{
	private function makeToken(array $overrides = []): RefreshToken
	{
		$defaults = [
			'id'        => 1,
			'userId'    => 42,
			'familyId'  => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
			'tokenHash' => str_repeat('a', 64),
			'issuedAt'  => new \DateTimeImmutable('-2 hours'),
			'expiresAt' => new \DateTimeImmutable('+1 hour'),
			'revokedAt' => null,
		];

		$data = array_merge($defaults, $overrides);

		return new RefreshToken(
			id:        $data['id'],
			userId:    $data['userId'],
			familyId:  $data['familyId'],
			tokenHash: $data['tokenHash'],
			issuedAt:  $data['issuedAt'],
			expiresAt: $data['expiresAt'],
			revokedAt: $data['revokedAt'],
		);
	}

	#[Test]
	public function itIsRevokedWhenRevokedAtIsSet(): void
	{
		$token = $this->makeToken(['revokedAt' => new \DateTimeImmutable('-1 hour')]);

		$this->assertTrue($token->isRevoked());
	}

	#[Test]
	public function itIsNotRevokedWhenRevokedAtIsNull(): void
	{
		$token = $this->makeToken(['revokedAt' => null]);

		$this->assertFalse($token->isRevoked());
	}

	#[Test]
	public function itIsExpiredWhenExpiresAtIsInPast(): void
	{
		$token = $this->makeToken(['expiresAt' => new \DateTimeImmutable('-1 hour')]);

		$this->assertTrue($token->isExpired());
	}

	#[Test]
	public function itIsNotExpiredWhenExpiresAtIsInFuture(): void
	{
		$token = $this->makeToken(['expiresAt' => new \DateTimeImmutable('+1 hour')]);

		$this->assertFalse($token->isExpired());
	}

	#[Test]
	public function itIsValidWhenNotRevokedAndNotExpired(): void
	{
		$validToken = $this->makeToken([
			'expiresAt' => new \DateTimeImmutable('+1 hour'),
			'revokedAt' => null,
		]);
		$this->assertTrue($validToken->isValid());

		$expiredToken = $this->makeToken([
			'expiresAt' => new \DateTimeImmutable('-1 hour'),
			'revokedAt' => null,
		]);
		$this->assertFalse($expiredToken->isValid());
	}
}

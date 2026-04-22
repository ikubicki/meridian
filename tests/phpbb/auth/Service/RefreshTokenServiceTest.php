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

namespace phpbb\tests\auth\Service;

use phpbb\auth\Contract\RefreshTokenRepositoryInterface;
use phpbb\auth\Entity\RefreshToken;
use phpbb\auth\Service\RefreshTokenService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RefreshTokenServiceTest extends TestCase
{
	private RefreshTokenRepositoryInterface $repo;
	private RefreshTokenService $service;

	protected function setUp(): void
	{
		$this->repo    = $this->createMock(RefreshTokenRepositoryInterface::class);
		$this->service = new RefreshTokenService($this->repo);
	}

	#[Test]
	public function itIssuesFamilyReturnsRawTokenAndFamilyId(): void
	{
		$this->repo->expects(self::once())->method('save');

		$result = $this->service->issueFamily(1);

		self::assertArrayHasKey('rawToken', $result);
		self::assertArrayHasKey('familyId', $result);
	}

	#[Test]
	public function itSavesHashedTokenNotRawToken(): void
	{
		$savedToken = null;

		$this->repo
			->expects(self::once())
			->method('save')
			->willReturnCallback(function (RefreshToken $token) use (&$savedToken): void {
				$savedToken = $token;
			});

		$result = $this->service->issueFamily(1);

		self::assertNotNull($savedToken);
		self::assertSame(hash('sha256', $result['rawToken']), $savedToken->tokenHash);
	}

	#[Test]
	public function itRotatesFamilyRevokesOldTokenAndSavesNew(): void
	{
		$existing = new RefreshToken(
			id:        1,
			userId:    7,
			familyId:  'family-abc',
			tokenHash: 'oldhash',
			issuedAt:  new \DateTimeImmutable(),
			expiresAt: new \DateTimeImmutable('+30 days'),
			revokedAt: null,
		);

		$this->repo
			->expects(self::once())
			->method('revokeByHash')
			->with('oldhash');

		$this->repo
			->expects(self::once())
			->method('save');

		$this->service->rotateFamily($existing);
	}

	#[Test]
	public function itRotatesFamilyPreservesFamilyId(): void
	{
		$existing = new RefreshToken(
			id:        1,
			userId:    7,
			familyId:  'family-abc',
			tokenHash: 'oldhash',
			issuedAt:  new \DateTimeImmutable(),
			expiresAt: new \DateTimeImmutable('+30 days'),
			revokedAt: null,
		);

		$savedToken = null;

		$this->repo->method('revokeByHash');
		$this->repo
			->expects(self::once())
			->method('save')
			->willReturnCallback(function (RefreshToken $token) use (&$savedToken): void {
				$savedToken = $token;
			});

		$this->service->rotateFamily($existing);

		self::assertNotNull($savedToken);
		self::assertSame('family-abc', $savedToken->familyId);
	}
}

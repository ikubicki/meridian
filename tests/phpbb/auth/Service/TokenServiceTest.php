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

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use phpbb\auth\Entity\TokenPayload;
use phpbb\auth\Service\TokenService;
use phpbb\user\Entity\User;
use phpbb\user\Enum\InactiveReason;
use phpbb\user\Enum\UserType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TokenServiceTest extends TestCase
{
	private const JWT_SECRET = 'test-secret';

	private TokenService $service;

	protected function setUp(): void
	{
		$this->service = new TokenService(self::JWT_SECRET);
	}

	private function makeUser(): User
	{
		return new User(
			id:               42,
			type:             UserType::Normal,
			username:         'testuser',
			usernameClean:    'testuser',
			email:            'test@example.com',
			passwordHash:     '$argon2id$test',
			colour:           '',
			defaultGroupId:   2,
			avatarUrl:        '',
			registeredAt:     new \DateTimeImmutable('2024-01-01'),
			lastmark:         new \DateTimeImmutable('2024-01-01'),
			posts:            0,
			lastPostTime:     null,
			isNew:            false,
			rank:             0,
			registrationIp:   '127.0.0.1',
			loginAttempts:    0,
			inactiveReason:   null,
			formSalt:         'salt',
			activationKey:    'key',
			tokenGeneration:  1,
			permVersion:      2,
		);
	}

	#[Test]
	public function itIssuesAccessTokenWithCorrectAud(): void
	{
		$user  = $this->makeUser();
		$token = $this->service->issueAccessToken($user);

		$derivedKey = hash_hmac('sha256', 'jwt-access-v1', self::JWT_SECRET, true);
		$claims     = JWT::decode($token, new Key($derivedKey, 'HS256'));

		self::assertSame('phpbb-api', $claims->aud);
	}

	#[Test]
	public function itDecodesAccessTokenReturnsTokenPayload(): void
	{
		$user    = $this->makeUser();
		$rawToken = $this->service->issueAccessToken($user);

		$payload = $this->service->decodeToken($rawToken, 'phpbb-api');

		self::assertInstanceOf(TokenPayload::class, $payload);
		self::assertSame(42, $payload->sub);
	}

	#[Test]
	public function itUsesDistinctKeyForAccessVsElevatedToken(): void
	{
		$service = new TokenService('test-secret-for-key-derivation-test');
		$user    = $this->makeUser();

		$accessToken = $service->issueAccessToken($user);

		// Decoding an access token with the elevated audience must throw
		$this->expectException(\UnexpectedValueException::class);
		$service->decodeToken($accessToken, 'phpbb-admin');
	}
}

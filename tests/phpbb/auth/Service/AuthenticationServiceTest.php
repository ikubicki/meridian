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
use phpbb\auth\Contract\RefreshTokenServiceInterface;
use phpbb\auth\Contract\TokenServiceInterface;
use phpbb\auth\Entity\RefreshToken;
use phpbb\auth\Exception\AuthenticationFailedException;
use phpbb\auth\Exception\InvalidRefreshTokenException;
use phpbb\auth\Service\AuthenticationService;
use phpbb\user\Contract\PasswordServiceInterface;
use phpbb\user\Contract\UserRepositoryInterface;
use phpbb\user\Entity\User;
use phpbb\user\Enum\UserType;
use phpbb\user\Exception\BannedException;
use phpbb\user\Service\BanService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuthenticationServiceTest extends TestCase
{
	private AuthenticationService $service;
	private UserRepositoryInterface $userRepo;
	private PasswordServiceInterface $passwordService;
	private BanService $banService;
	private TokenServiceInterface $tokenService;
	private RefreshTokenServiceInterface $refreshTokenService;
	private RefreshTokenRepositoryInterface $refreshTokenRepo;

	protected function setUp(): void
	{
		$this->userRepo            = $this->createMock(UserRepositoryInterface::class);
		$this->passwordService     = $this->createMock(PasswordServiceInterface::class);
		$this->banService          = $this->createMock(BanService::class);
		$this->tokenService        = $this->createMock(TokenServiceInterface::class);
		$this->refreshTokenService = $this->createMock(RefreshTokenServiceInterface::class);
		$this->refreshTokenRepo    = $this->createMock(RefreshTokenRepositoryInterface::class);

		$this->service = new AuthenticationService(
			userRepository:       $this->userRepo,
			passwordService:      $this->passwordService,
			banService:           $this->banService,
			tokenService:         $this->tokenService,
			refreshTokenService:  $this->refreshTokenService,
			refreshTokenRepository: $this->refreshTokenRepo,
		);
	}

	private function makeUser(): User
	{
		return new User(
			id:             42,
			type:           UserType::Normal,
			username:       'testuser',
			usernameClean:  'testuser',
			email:          'test@example.com',
			passwordHash:   '$argon2id$test',
			colour:         '',
			defaultGroupId: 2,
			avatarUrl:      '',
			registeredAt:   new \DateTimeImmutable('2024-01-01'),
			lastmark:       new \DateTimeImmutable('2024-01-01'),
			posts:          0,
			lastPostTime:   null,
			isNew:          false,
			rank:           0,
			registrationIp: '127.0.0.1',
			loginAttempts:  0,
			inactiveReason: null,
			formSalt:       '',
			activationKey:  '',
		);
	}

	#[Test]
	public function itLoginReturnsTokensOnValidCredentials(): void
	{
		$user = $this->makeUser();

		$this->userRepo->method('findByUsername')->willReturn($user);
		$this->passwordService->method('verifyPassword')->willReturn(true);
		$this->banService->method('assertNotBanned');
		$this->refreshTokenService->method('issueFamily')->willReturn(['rawToken' => 'r', 'familyId' => 'f']);
		$this->tokenService->method('issueAccessToken')->willReturn('jwt');

		$result = $this->service->login('testuser', 'secret', '127.0.0.1');

		$this->assertSame('jwt', $result['accessToken']);
		$this->assertSame('r', $result['refreshToken']);
		$this->assertArrayHasKey('expiresIn', $result);
	}

	#[Test]
	public function itLoginThrowsAuthenticationFailedWhenUserNotFound(): void
	{
		$this->userRepo->method('findByUsername')->willReturn(null);

		$this->expectException(AuthenticationFailedException::class);
		$this->service->login('nobody', 'secret', '127.0.0.1');
	}

	#[Test]
	public function itLoginThrowsAuthenticationFailedOnWrongPassword(): void
	{
		$this->userRepo->method('findByUsername')->willReturn($this->makeUser());
		$this->passwordService->method('verifyPassword')->willReturn(false);

		$this->expectException(AuthenticationFailedException::class);
		$this->service->login('testuser', 'wrong', '127.0.0.1');
	}

	#[Test]
	public function itLoginThrowsBannedExceptionWhenBanServiceThrows(): void
	{
		$this->userRepo->method('findByUsername')->willReturn($this->makeUser());
		$this->passwordService->method('verifyPassword')->willReturn(true);
		$this->banService->method('assertNotBanned')->willThrowException(new BannedException('banned'));

		$this->expectException(BannedException::class);
		$this->service->login('testuser', 'secret', '127.0.0.1');
	}

	#[Test]
	public function itLogoutRevokesTokensAndIncrementsGeneration(): void
	{
		$this->refreshTokenRepo->expects($this->once())
			->method('revokeAllForUser')
			->with(42);

		$this->userRepo->expects($this->once())
			->method('incrementTokenGeneration')
			->with(42);

		$this->service->logout(42);
	}

	#[Test]
	public function itRefreshThrowsInvalidRefreshTokenExceptionWhenNotFound(): void
	{
		$this->refreshTokenRepo->method('findByHash')->willReturn(null);

		$this->expectException(InvalidRefreshTokenException::class);
		$this->service->refresh('raw-token-value');
	}

	#[Test]
	public function itRefreshRevokesEntireFamilyOnTokenReuseDetection(): void
	{
		$revokedToken = new RefreshToken(
			id:        1,
			userId:    99,
			familyId:  'steal-family',
			tokenHash: hash('sha256', 'stolen-token'),
			issuedAt:  new \DateTimeImmutable(),
			expiresAt: new \DateTimeImmutable('+30 days'),
			revokedAt: new \DateTimeImmutable('-1 min'),
		);

		$this->refreshTokenRepo->method('findByHash')->willReturn($revokedToken);

		$this->refreshTokenRepo->expects($this->once())
			->method('revokeFamily')
			->with('steal-family');

		$this->expectException(InvalidRefreshTokenException::class);
		$this->service->refresh('stolen-token');
	}

	#[Test]
	public function itElevateThrowsAuthenticationFailedOnWrongPassword(): void
	{
		$this->userRepo->method('findById')->willReturn($this->makeUser());
		$this->passwordService->method('verifyPassword')->willReturn(false);

		$this->expectException(AuthenticationFailedException::class);
		$this->service->elevate(42, 'wrong-password');
	}
}

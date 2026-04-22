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

namespace phpbb\auth\Service;

use phpbb\auth\Contract\AuthenticationServiceInterface;
use phpbb\auth\Contract\RefreshTokenRepositoryInterface;
use phpbb\auth\Contract\RefreshTokenServiceInterface;
use phpbb\auth\Contract\TokenServiceInterface;
use phpbb\auth\Exception\AuthenticationFailedException;
use phpbb\auth\Exception\InvalidRefreshTokenException;
use phpbb\user\Contract\PasswordServiceInterface;
use phpbb\user\Contract\UserRepositoryInterface;
use phpbb\user\Service\BanService;

final class AuthenticationService implements AuthenticationServiceInterface
{
	public function __construct(
		private readonly UserRepositoryInterface $userRepository,
		private readonly PasswordServiceInterface $passwordService,
		private readonly BanService $banService,
		private readonly TokenServiceInterface $tokenService,
		private readonly RefreshTokenServiceInterface $refreshTokenService,
		private readonly RefreshTokenRepositoryInterface $refreshTokenRepository,
	) {
	}

	public function login(string $username, string $password, string $ip): array
	{
		$user = $this->userRepository->findByUsername($username);

		if ($user === null) {
			throw new AuthenticationFailedException('Invalid credentials.');
		}

		if (!$this->passwordService->verifyPassword($password, $user->passwordHash)) {
			throw new AuthenticationFailedException('Invalid credentials.');
		}

		$this->banService->assertNotBanned($user->id, $ip, $user->email);

		$result      = $this->refreshTokenService->issueFamily($user->id);
		$accessToken = $this->tokenService->issueAccessToken($user);

		return [
			'accessToken'  => $accessToken,
			'refreshToken' => $result['rawToken'],
			'expiresIn'    => 900,
		];
	}

	public function logout(int $userId): void
	{
		$this->refreshTokenRepository->revokeAllForUser($userId);
		$this->userRepository->incrementTokenGeneration($userId);
	}

	public function refresh(string $rawRefreshToken): array
	{
		$hash  = hash('sha256', $rawRefreshToken);
		$token = $this->refreshTokenRepository->findByHash($hash);

		if ($token === null) {
			throw new InvalidRefreshTokenException('Refresh token not found.');
		}

		if ($token->isRevoked()) {
			$this->refreshTokenRepository->revokeFamily($token->familyId);

			throw new InvalidRefreshTokenException('Token reuse detected.');
		}

		if ($token->isExpired()) {
			throw new InvalidRefreshTokenException('Refresh token has expired.');
		}

		$result = $this->refreshTokenService->rotateFamily($token);
		$user   = $this->userRepository->findById($token->userId);

		if ($user === null) {
			throw new InvalidRefreshTokenException('User not found.');
		}

		$accessToken = $this->tokenService->issueAccessToken($user);

		return [
			'accessToken'  => $accessToken,
			'refreshToken' => $result['rawToken'],
			'expiresIn'    => 900,
		];
	}

	public function elevate(int $userId, string $password): array
	{
		$user = $this->userRepository->findById($userId);

		if ($user === null) {
			throw new AuthenticationFailedException('User not found.');
		}

		if (!$this->passwordService->verifyPassword($password, $user->passwordHash)) {
			throw new AuthenticationFailedException('Invalid credentials.');
		}

		$elevatedToken = $this->tokenService->issueElevatedToken($user);

		return [
			'elevatedToken' => $elevatedToken,
			'expiresIn'     => 300,
		];
	}
}

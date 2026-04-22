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

use phpbb\auth\Contract\RefreshTokenRepositoryInterface;
use phpbb\auth\Contract\RefreshTokenServiceInterface;
use phpbb\auth\Entity\RefreshToken;

final class RefreshTokenService implements RefreshTokenServiceInterface
{
	public function __construct(
		private readonly RefreshTokenRepositoryInterface $refreshTokenRepository,
		private readonly int $refreshTtlDays = 30,
	) {}

	private function uuid4(): string
	{
		$data    = random_bytes(16);
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40);
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}

	public function issueFamily(int $userId): array
	{
		$familyId   = $this->uuid4();
		$rawToken   = $this->uuid4();
		$tokenHash  = hash('sha256', $rawToken);
		$now        = new \DateTimeImmutable();

		$token = new RefreshToken(
			id:        0,
			userId:    $userId,
			familyId:  $familyId,
			tokenHash: $tokenHash,
			issuedAt:  $now,
			expiresAt: $now->modify("+{$this->refreshTtlDays} days"),
			revokedAt: null,
		);

		$this->refreshTokenRepository->save($token);

		return ['rawToken' => $rawToken, 'familyId' => $familyId];
	}

	public function rotateFamily(RefreshToken $existingToken): array
	{
		$this->refreshTokenRepository->revokeByHash($existingToken->tokenHash);

		$rawToken  = $this->uuid4();
		$tokenHash = hash('sha256', $rawToken);
		$now       = new \DateTimeImmutable();

		$newToken = new RefreshToken(
			id:        0,
			userId:    $existingToken->userId,
			familyId:  $existingToken->familyId,
			tokenHash: $tokenHash,
			issuedAt:  $now,
			expiresAt: $now->modify("+{$this->refreshTtlDays} days"),
			revokedAt: null,
		);

		$this->refreshTokenRepository->save($newToken);

		return ['rawToken' => $rawToken];
	}
}

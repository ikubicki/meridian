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

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use phpbb\auth\Contract\TokenServiceInterface;
use phpbb\auth\Entity\TokenPayload;
use phpbb\user\Entity\User;

final class TokenService implements TokenServiceInterface
{
	public function __construct(
		private readonly string $jwtSecret,
		private readonly int $accessTtl = 900,
		private readonly int $elevatedTtl = 300,
	) {
	}

	private function deriveKey(string $context): string
	{
		return hash_hmac('sha256', $context, $this->jwtSecret, true);
	}

	private function uuid4(): string
	{
		$data    = random_bytes(16);
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40);
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}

	public function issueAccessToken(User $user): string
	{
		$key    = $this->deriveKey('jwt-access-v1');
		$now    = time();
		$claims = [
			'iss'   => 'phpbb4',
			'sub'   => $user->id,
			'aud'   => 'phpbb-api',
			'iat'   => $now,
			'exp'   => $now + $this->accessTtl,
			'jti'   => $this->uuid4(),
			'gen'   => $user->tokenGeneration,
			'pv'    => $user->permVersion,
			'utype' => $user->type->value,
			'flags' => '',
			'kid'   => 'access-v1',
		];

		return JWT::encode($claims, $key, 'HS256');
	}

	public function issueElevatedToken(User $user): string
	{
		$key    = $this->deriveKey('jwt-elevated-v1');
		$now    = time();
		$claims = [
			'iss'   => 'phpbb4',
			'sub'   => $user->id,
			'aud'   => 'phpbb-admin',
			'iat'   => $now,
			'exp'   => $now + $this->elevatedTtl,
			'jti'   => $this->uuid4(),
			'gen'   => $user->tokenGeneration,
			'pv'    => $user->permVersion,
			'utype' => $user->type->value,
			'flags' => '',
			'kid'   => 'elevated-v1',
			'scope' => ['acp', 'mcp'],
		];

		return JWT::encode($claims, $key, 'HS256');
	}

	public function decodeToken(string $rawToken, string $expectedAud): TokenPayload
	{
		$context = match ($expectedAud) {
			'phpbb-api'   => 'jwt-access-v1',
			'phpbb-admin' => 'jwt-elevated-v1',
			default       => throw new \UnexpectedValueException("Unknown audience: $expectedAud"),
		};

		$key    = new Key($this->deriveKey($context), 'HS256');
		$claims = JWT::decode($rawToken, $key);

		if ($claims->aud !== $expectedAud) {
			throw new \UnexpectedValueException(
				"Expected audience '$expectedAud', got '{$claims->aud}'"
			);
		}

		return TokenPayload::fromStdClass($claims);
	}
}

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

use phpbb\auth\Entity\TokenPayload;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TokenPayloadTest extends TestCase
{
	#[Test]
	public function itBuildsTokenPayloadFromStdClass(): void
	{
		$claims = new \stdClass();
		$claims->iss   = 'phpbb4';
		$claims->sub   = '99';
		$claims->aud   = 'api';
		$claims->iat   = 1700000000;
		$claims->exp   = 1700003600;
		$claims->jti   = 'some-uuid';
		$claims->gen   = '3';
		$claims->pv    = '1';
		$claims->utype = '0';
		$claims->flags = 'abc';

		$payload = TokenPayload::fromStdClass($claims);

		$this->assertSame('phpbb4', $payload->iss);
		$this->assertIsInt($payload->sub);
		$this->assertSame(99, $payload->sub);
		$this->assertSame('api', $payload->aud);
		$this->assertSame(1700000000, $payload->iat);
		$this->assertSame(1700003600, $payload->exp);
		$this->assertSame('some-uuid', $payload->jti);
		$this->assertIsInt($payload->gen);
		$this->assertSame(3, $payload->gen);
		$this->assertIsInt($payload->pv);
		$this->assertSame(1, $payload->pv);
		$this->assertIsInt($payload->utype);
		$this->assertSame(0, $payload->utype);
		$this->assertSame('abc', $payload->flags);
	}
}

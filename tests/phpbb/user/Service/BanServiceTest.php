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

namespace phpbb\Tests\user\Service;

use phpbb\user\Contract\BanRepositoryInterface;
use phpbb\user\Exception\BannedException;
use phpbb\user\Service\BanService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BanServiceTest extends TestCase
{
	private BanRepositoryInterface&MockObject $repo;
	private BanService $service;

	protected function setUp(): void
	{
		$this->repo    = $this->createMock(BanRepositoryInterface::class);
		$this->service = new BanService($this->repo);
	}

	#[Test]
	public function isUserBannedReturnsTrueWhenBanned(): void
	{
		$this->repo->method('isUserBanned')->with(7)->willReturn(true);

		self::assertTrue($this->service->isUserBanned(7));
	}

	#[Test]
	public function isUserBannedReturnsFalseWhenNotBanned(): void
	{
		$this->repo->method('isUserBanned')->willReturn(false);

		self::assertFalse($this->service->isUserBanned(1));
	}

	#[Test]
	public function isIpBannedDelegatesToRepository(): void
	{
		$this->repo->method('isIpBanned')->with('10.0.0.1')->willReturn(true);

		self::assertTrue($this->service->isIpBanned('10.0.0.1'));
	}

	#[Test]
	public function isEmailBannedDelegatesToRepository(): void
	{
		$this->repo->method('isEmailBanned')->with('spam@evil.com')->willReturn(true);

		self::assertTrue($this->service->isEmailBanned('spam@evil.com'));
	}

	#[Test]
	public function assertNotBannedPassesForCleanActor(): void
	{
		$this->repo->method('isUserBanned')->willReturn(false);
		$this->repo->method('isIpBanned')->willReturn(false);
		$this->repo->method('isEmailBanned')->willReturn(false);

		// Must not throw
		$this->service->assertNotBanned(1, '127.0.0.1', 'clean@example.com');

		self::assertTrue(true); // reached
	}

	#[Test]
	public function assertNotBannedThrowsWhenUserIsBanned(): void
	{
		$this->repo->method('isUserBanned')->with(5)->willReturn(true);

		$this->expectException(BannedException::class);
		$this->service->assertNotBanned(5, '127.0.0.1', 'ok@example.com');
	}

	#[Test]
	public function assertNotBannedThrowsWhenIpIsBanned(): void
	{
		$this->repo->method('isUserBanned')->willReturn(false);
		$this->repo->method('isIpBanned')->with('1.2.3.4')->willReturn(true);

		$this->expectException(BannedException::class);
		$this->service->assertNotBanned(1, '1.2.3.4', 'ok@example.com');
	}

	#[Test]
	public function assertNotBannedThrowsWhenEmailIsBanned(): void
	{
		$this->repo->method('isUserBanned')->willReturn(false);
		$this->repo->method('isIpBanned')->willReturn(false);
		$this->repo->method('isEmailBanned')->with('bad@evil.com')->willReturn(true);

		$this->expectException(BannedException::class);
		$this->service->assertNotBanned(1, '127.0.0.1', 'bad@evil.com');
	}
}

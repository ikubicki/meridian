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

namespace phpbb\tests\user\Service;

use phpbb\user\Service\PasswordService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PasswordServiceTest extends TestCase
{
	private PasswordService $service;

	protected function setUp(): void
	{
		$this->service = new PasswordService();
	}

	#[Test]
	public function itHashesPasswordWithArgon2id(): void
	{
		$hash = $this->service->hashPassword('secret');

		self::assertStringStartsWith('$argon2id$', $hash);
	}

	#[Test]
	public function itVerifiesValidPassword(): void
	{
		$hash = $this->service->hashPassword('correct-horse');

		self::assertTrue($this->service->verifyPassword('correct-horse', $hash));
	}

	#[Test]
	public function itRejectsInvalidPassword(): void
	{
		$hash = $this->service->hashPassword('correct-horse');

		self::assertFalse($this->service->verifyPassword('wrong-horse', $hash));
	}

	#[Test]
	public function itDetectsNeedsRehash(): void
	{
		$hash = $this->service->hashPassword('password');

		self::assertFalse($this->service->needsRehash($hash));
	}
}

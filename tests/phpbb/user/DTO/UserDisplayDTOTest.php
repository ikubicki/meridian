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

namespace phpbb\Tests\user\DTO;

use phpbb\user\DTO\UserDisplayDTO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class UserDisplayDTOTest extends TestCase
{
	#[Test]
	public function constructorAssignsAllFields(): void
	{
		$dto = new UserDisplayDTO(id: 7, username: 'bob', colour: '0000FF', avatarUrl: '/avatars/bob.png');

		self::assertSame(7, $dto->id);
		self::assertSame('bob', $dto->username);
		self::assertSame('0000FF', $dto->colour);
		self::assertSame('/avatars/bob.png', $dto->avatarUrl);
	}

	#[Test]
	public function emptyAvatarUrlIsAllowed(): void
	{
		$dto = new UserDisplayDTO(id: 1, username: 'anon', colour: '', avatarUrl: '');
		self::assertSame('', $dto->avatarUrl);
	}
}

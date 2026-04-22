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

namespace phpbb\Tests\user\Entity;

use phpbb\user\Entity\User;
use phpbb\user\Enum\InactiveReason;
use phpbb\user\Enum\UserType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
	private function makeUser(array $overrides = []): User
	{
		$defaults = [
			'id'             => 42,
			'type'           => UserType::Normal,
			'username'       => 'alice',
			'usernameClean'  => 'alice',
			'email'          => 'alice@example.com',
			'passwordHash'   => '$2y$10$hash',
			'colour'         => 'FF0000',
			'defaultGroupId' => 2,
			'avatarUrl'      => '',
			'registeredAt'   => new \DateTimeImmutable('2026-01-01'),
			'lastmark'       => new \DateTimeImmutable('2026-06-01'),
			'posts'          => 7,
			'lastPostTime'   => null,
			'isNew'          => false,
			'rank'           => 0,
			'registrationIp' => '127.0.0.1',
			'loginAttempts'  => 0,
			'inactiveReason' => null,
			'formSalt'       => 'salt',
			'activationKey'  => '',
		];

		$defaults['tokenGeneration'] = 0;
		$defaults['permVersion']     = 0;
		$args = array_merge($defaults, $overrides);

		return new User(
			id: $args['id'],
			type: $args['type'],
			username: $args['username'],
			usernameClean: $args['usernameClean'],
			email: $args['email'],
			passwordHash: $args['passwordHash'],
			colour: $args['colour'],
			defaultGroupId: $args['defaultGroupId'],
			avatarUrl: $args['avatarUrl'],
			registeredAt: $args['registeredAt'],
			lastmark: $args['lastmark'],
			posts: $args['posts'],
			lastPostTime: $args['lastPostTime'],
			isNew: $args['isNew'],
			rank: $args['rank'],
			registrationIp: $args['registrationIp'],
			loginAttempts: $args['loginAttempts'],
			inactiveReason: $args['inactiveReason'],
			formSalt: $args['formSalt'],
			activationKey: $args['activationKey'],
			tokenGeneration: $args['tokenGeneration'],
			permVersion: $args['permVersion'],
		);
	}

	#[Test]
	public function constructorAssignsAllProperties(): void
	{
		$user = $this->makeUser();

		self::assertSame(42, $user->id);
		self::assertSame(UserType::Normal, $user->type);
		self::assertSame('alice', $user->username);
		self::assertSame('alice@example.com', $user->email);
		self::assertSame('FF0000', $user->colour);
		self::assertSame(2, $user->defaultGroupId);
		self::assertSame(7, $user->posts);
		self::assertFalse($user->isNew);
		self::assertNull($user->inactiveReason);
	}

	#[Test]
	public function founderTypeIsStoredCorrectly(): void
	{
		$user = $this->makeUser(['type' => UserType::Founder]);
		self::assertSame(UserType::Founder, $user->type);
		self::assertSame(3, $user->type->value);
	}

	#[Test]
	public function inactiveReasonIsStoredWhenSet(): void
	{
		$user = $this->makeUser([
			'type'           => UserType::Inactive,
			'inactiveReason' => InactiveReason::Register,
		]);

		self::assertSame(InactiveReason::Register, $user->inactiveReason);
		self::assertSame(1, $user->inactiveReason->value);
	}

	#[Test]
	public function lastPostTimeCanBeNull(): void
	{
		$user = $this->makeUser(['lastPostTime' => null]);
		self::assertNull($user->lastPostTime);
	}

	#[Test]
	public function lastPostTimeIsDateTimeImmutableWhenSet(): void
	{
		$dt   = new \DateTimeImmutable('2026-05-10');
		$user = $this->makeUser(['lastPostTime' => $dt]);
		self::assertSame($dt, $user->lastPostTime);
	}

	#[Test]
	public function itHasTokenGenerationDefaultZero(): void
	{
		$user = $this->makeUser();
		self::assertSame(0, $user->tokenGeneration);
	}

	#[Test]
	public function itHasPermVersionDefaultZero(): void
	{
		$user = $this->makeUser();
		self::assertSame(0, $user->permVersion);
	}

	#[Test]
	public function itExposesTokenGenerationAsInt(): void
	{
		$user = $this->makeUser(['tokenGeneration' => 5]);
		self::assertIsInt($user->tokenGeneration);
		self::assertSame(5, $user->tokenGeneration);
	}

	#[Test]
	public function itExposesPermVersionAsInt(): void
	{
		$user = $this->makeUser(['permVersion' => 3]);
		self::assertIsInt($user->permVersion);
		self::assertSame(3, $user->permVersion);
	}
}

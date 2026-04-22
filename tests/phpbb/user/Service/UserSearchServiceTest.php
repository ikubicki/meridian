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

use phpbb\user\Contract\UserRepositoryInterface;
use phpbb\user\DTO\PaginatedResult;
use phpbb\user\DTO\UserSearchCriteria;
use phpbb\user\Entity\User;
use phpbb\user\Enum\UserType;
use phpbb\user\Service\UserSearchService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UserSearchServiceTest extends TestCase
{
	private UserRepositoryInterface&MockObject $repo;
	private UserSearchService $service;

	protected function setUp(): void
	{
		$this->repo    = $this->createMock(UserRepositoryInterface::class);
		$this->service = new UserSearchService($this->repo);
	}

	private function makeUser(int $id = 1): User
	{
		return new User(
			id: $id,
			type: UserType::Normal,
			username: 'alice',
			usernameClean: 'alice',
			email: 'alice@example.com',
			passwordHash: 'hash',
			colour: '',
			defaultGroupId: 2,
			avatarUrl: '',
			registeredAt: new \DateTimeImmutable(),
			lastmark: new \DateTimeImmutable(),
			posts: 0,
			lastPostTime: null,
			isNew: false,
			rank: 0,
			registrationIp: '127.0.0.1',
			loginAttempts: 0,
			inactiveReason: null,
			formSalt: 'salt',
			activationKey: '',
		);
	}

	#[Test]
	public function findByIdDelegatesToRepository(): void
	{
		$user = $this->makeUser(5);
		$this->repo->expects(self::once())->method('findById')->with(5)->willReturn($user);

		$result = $this->service->findById(5);

		self::assertSame($user, $result);
	}

	#[Test]
	public function findByIdReturnsNullWhenNotFound(): void
	{
		$this->repo->method('findById')->willReturn(null);

		self::assertNull($this->service->findById(999));
	}

	#[Test]
	public function findByUsernameDelegatesToRepository(): void
	{
		$user = $this->makeUser(3);
		$this->repo->expects(self::once())->method('findByUsername')->with('alice')->willReturn($user);

		$result = $this->service->findByUsername('alice');

		self::assertSame($user, $result);
	}

	#[Test]
	public function findByEmailDelegatesToRepository(): void
	{
		$user = $this->makeUser(3);
		$this->repo->expects(self::once())->method('findByEmail')->with('alice@example.com')->willReturn($user);

		$result = $this->service->findByEmail('alice@example.com');

		self::assertSame($user, $result);
	}

	#[Test]
	public function searchDelegatesToRepositoryAndReturnsPaginatedResult(): void
	{
		$criteria = new UserSearchCriteria(query: 'alice', page: 1, perPage: 10);
		$paginated = new PaginatedResult([$this->makeUser()], 1, 1, 10);

		$this->repo->expects(self::once())->method('search')->with($criteria)->willReturn($paginated);

		$result = $this->service->search($criteria);

		self::assertSame(1, $result->total);
		self::assertCount(1, $result->items);
	}
}

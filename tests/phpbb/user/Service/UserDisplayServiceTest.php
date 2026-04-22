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

use phpbb\cache\CachePool;
use phpbb\cache\CachePoolFactoryInterface;
use phpbb\cache\marshaller\VarExportMarshaller;
use phpbb\cache\TagVersionStore;
use phpbb\cache\backend\NullBackend;
use phpbb\user\Contract\UserRepositoryInterface;
use phpbb\user\DTO\UserDisplayDTO;
use phpbb\user\Service\UserDisplayService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * In-memory cache backend for display-service tests.
 */
class InMemoryDisplayBackend extends NullBackend
{
	/** @var array<string, string> */
	private array $store = [];

	public function get(string $key): ?string
	{
		return $this->store[$key] ?? null;
	}

	public function set(string $key, string $value, ?int $ttl = null): bool
	{
		$this->store[$key] = $value;
		return true;
	}

	public function has(string $key): bool
	{
		return isset($this->store[$key]);
	}
}

class UserDisplayServiceTest extends TestCase
{
	private UserRepositoryInterface&MockObject $repo;
	private UserDisplayService $service;

	protected function setUp(): void
	{
		$this->repo = $this->createMock(UserRepositoryInterface::class);

		$backend    = new InMemoryDisplayBackend();
		$marshaller = new VarExportMarshaller();
		$tagStore   = new TagVersionStore($backend, $marshaller);

		$poolFactory = $this->createMock(CachePoolFactoryInterface::class);
		$poolFactory->method('getPool')->willReturnCallback(
			static fn (string $ns) => new CachePool($ns, $backend, $marshaller, $tagStore),
		);

		$this->service = new UserDisplayService($this->repo, $poolFactory);
	}

	#[Test]
	public function returnsEmptyArrayForNoIds(): void
	{
		$this->repo->expects(self::never())->method('findDisplayByIds');

		$result = $this->service->findDisplayByIds([]);

		self::assertSame([], $result);
	}

	#[Test]
	public function fetchesMissingIdsFromRepository(): void
	{
		$dto = new UserDisplayDTO(id: 1, username: 'alice', colour: '', avatarUrl: '');

		$this->repo->expects(self::once())
			->method('findDisplayByIds')
			->with([1])
			->willReturn([1 => $dto]);

		$result = $this->service->findDisplayByIds([1]);

		self::assertArrayHasKey(1, $result);
		self::assertSame($dto, $result[1]);
	}

	#[Test]
	public function secondCallForSameIdHitsCache(): void
	{
		$dto = new UserDisplayDTO(id: 2, username: 'bob', colour: '', avatarUrl: '');

		$this->repo->expects(self::once())
			->method('findDisplayByIds')
			->willReturn([2 => $dto]);

		$this->service->findDisplayByIds([2]);
		$secondResult = $this->service->findDisplayByIds([2]);

		self::assertArrayHasKey(2, $secondResult);
	}
}

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

namespace phpbb\Tests\config;

use Doctrine\DBAL\Connection;
use phpbb\config\ConfigRepository;
use phpbb\config\Contract\ConfigRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigRepositoryTest extends TestCase
{
	private Connection&MockObject $connection;
	private ConfigRepository $repository;

	protected function setUp(): void
	{
		$this->connection = $this->createMock(Connection::class);
		$this->repository = new ConfigRepository($this->connection);
	}

	#[Test]
	public function it_returns_config_value_for_existing_key(): void
	{
		$this->connection->expects($this->once())
			->method('fetchAssociative')
			->willReturn(['config_value' => 'like']);

		$result = $this->repository->get('search_driver', 'fulltext');

		$this->assertSame('like', $result);
	}

	#[Test]
	public function it_returns_default_when_key_missing(): void
	{
		$this->connection->expects($this->once())
			->method('fetchAssociative')
			->willReturn(false);

		$result = $this->repository->get('search_driver', 'fulltext');

		$this->assertSame('fulltext', $result);
	}

	#[Test]
	public function it_implements_config_repository_interface(): void
	{
		$this->assertInstanceOf(ConfigRepositoryInterface::class, $this->repository);
	}
}

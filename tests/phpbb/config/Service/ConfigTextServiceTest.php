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

namespace phpbb\Tests\config\Service;

use phpbb\config\Contract\ConfigTextRepositoryInterface;
use phpbb\config\Service\ConfigTextService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigTextServiceTest extends TestCase
{
	private ConfigTextRepositoryInterface&MockObject $repository;
	private ConfigTextService $service;

	protected function setUp(): void
	{
		$this->repository = $this->createMock(ConfigTextRepositoryInterface::class);
		$this->service    = new ConfigTextService($this->repository);
	}

	#[Test]
	public function getDelegatesToRepository(): void
	{
		$this->repository->method('get')->with('plugin.bbcode.config')->willReturn('{"enabled":true}');

		$result = $this->service->get('plugin.bbcode.config');

		$this->assertSame('{"enabled":true}', $result);
	}

	#[Test]
	public function getReturnsNullWhenKeyNotFound(): void
	{
		$this->repository->method('get')->with('missing_key')->willReturn(null);

		$result = $this->service->get('missing_key');

		$this->assertNull($result);
	}

	#[Test]
	public function setDelegatesToRepository(): void
	{
		$this->repository->expects($this->once())->method('set')->with('my_key', 'my_value');

		$this->service->set('my_key', 'my_value');
	}

	#[Test]
	public function deleteDelegatesToRepository(): void
	{
		$this->repository->expects($this->once())->method('delete')->with('my_key')->willReturn(1);

		$result = $this->service->delete('my_key');

		$this->assertSame(1, $result);
	}
}

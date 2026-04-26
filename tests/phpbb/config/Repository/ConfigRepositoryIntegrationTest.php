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

namespace phpbb\Tests\config\Repository;

use phpbb\config\ConfigRepository;
use phpbb\db\Exception\RepositoryException;
use phpbb\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;

class ConfigRepositoryIntegrationTest extends IntegrationTestCase
{
	private ConfigRepository $repository;

	protected function setUpSchema(): void
	{
		$this->connection->executeStatement(
			'CREATE TABLE phpbb_config (config_name TEXT PRIMARY KEY, config_value TEXT NOT NULL DEFAULT \'\', is_dynamic INTEGER NOT NULL DEFAULT 0)'
		);
	}

	protected function setUp(): void
	{
		parent::setUp();
		$this->repository = new ConfigRepository($this->connection);
	}

	#[Test]
	public function it_set_inserts_new_row(): void
	{
		$this->repository->set('test_key', 'test_value');

		$this->assertSame('test_value', $this->repository->get('test_key'));
	}

	#[Test]
	public function it_set_updates_existing_row_on_upsert(): void
	{
		$this->repository->set('test_key', 'first_value');
		$this->repository->set('test_key', 'second_value');

		$this->assertSame('second_value', $this->repository->get('test_key'));
	}

	#[Test]
	public function it_increment_atomically_increments_numeric_value(): void
	{
		$this->repository->set('count', '5');
		$this->repository->increment('count', 3);

		$this->assertSame('8', $this->repository->get('count'));
	}

	#[Test]
	public function it_getAll_returns_all_inserted_rows(): void
	{
		$this->repository->set('board_name', 'TestBoard');
		$this->repository->set('version', '3.3.0');

		$result = $this->repository->getAll();

		$this->assertSame([
			'board_name' => 'TestBoard',
			'version' => '3.3.0',
		], $result);
	}

	#[Test]
	public function it_delete_removes_row_and_returns_1_then_0(): void
	{
		$this->repository->set('test_key', 'test_value');

		$firstDelete = $this->repository->delete('test_key');
		$secondDelete = $this->repository->delete('test_key');

		$this->assertSame(1, $firstDelete);
		$this->assertSame(0, $secondDelete);
	}

	#[Test]
	public function it_get_returns_default_when_key_is_missing(): void
	{
		$result = $this->repository->get('nonexistent_key', 'my_default');

		$this->assertSame('my_default', $result);
	}

	#[Test]
	public function it_getAll_dynamic_only_returns_only_dynamic_rows(): void
	{
		$this->repository->set('static_key', 'static_val', false);
		$this->repository->set('dynamic_key', 'dynamic_val', true);

		$result = $this->repository->getAll(true);

		$this->assertArrayHasKey('dynamic_key', $result);
		$this->assertArrayNotHasKey('static_key', $result);
	}

	#[Test]
	public function it_increment_throws_repository_exception_on_nonexistent_key(): void
	{
		$this->expectException(RepositoryException::class);

		$this->repository->increment('nonexistent_key');
	}
}

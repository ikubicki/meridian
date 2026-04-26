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

use phpbb\config\ConfigTextRepository;
use phpbb\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;

class ConfigTextRepositoryTest extends IntegrationTestCase
{
	private ConfigTextRepository $repository;

	protected function setUpSchema(): void
	{
		$this->connection->executeStatement(
			'CREATE TABLE phpbb_config_text (
				config_name TEXT PRIMARY KEY NOT NULL,
				config_text TEXT NOT NULL DEFAULT ""
			)',
		);

		$this->repository = new ConfigTextRepository($this->connection);
	}

	#[Test]
	public function getReturnsNullForMissingKey(): void
	{
		$result = $this->repository->get('non_existent');

		$this->assertNull($result);
	}

	#[Test]
	public function setAndGetRoundTrip(): void
	{
		$this->repository->set('my_key', 'my long text value');

		$result = $this->repository->get('my_key');

		$this->assertSame('my long text value', $result);
	}

	#[Test]
	public function setUpdatesExistingKey(): void
	{
		$this->repository->set('my_key', 'original');
		$this->repository->set('my_key', 'updated');

		$this->assertSame('updated', $this->repository->get('my_key'));
	}

	#[Test]
	public function deleteRemovesKey(): void
	{
		$this->repository->set('to_delete', 'value');

		$affected = $this->repository->delete('to_delete');

		$this->assertSame(1, $affected);
		$this->assertNull($this->repository->get('to_delete'));
	}

	#[Test]
	public function deleteReturnsZeroForMissingKey(): void
	{
		$affected = $this->repository->delete('non_existent');

		$this->assertSame(0, $affected);
	}

	#[Test]
	public function setStoresLargeTextValues(): void
	{
		$largeText = str_repeat('a', 65536);
		$this->repository->set('large_key', $largeText);

		$this->assertSame($largeText, $this->repository->get('large_key'));
	}
}

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

namespace phpbb\Tests\user\Repository;

use phpbb\user\Repository\PdoUserRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PdoUserRepositoryTest extends TestCase
{
	/** @return array<string, mixed> */
	private function baseRow(): array
	{
		return [
			'user_id'             => '42',
			'user_type'           => '0',
			'username'            => 'alice',
			'username_clean'      => 'alice',
			'user_email'          => 'alice@example.com',
			'user_password'       => '$2y$10$hash',
			'user_colour'         => '',
			'group_id'            => '2',
			'user_avatar'         => '',
			'user_regdate'        => '1700000000',
			'user_lastmark'       => '1700000000',
			'user_posts'          => '0',
			'user_lastpost_time'  => '0',
			'user_new'            => '1',
			'user_rank'           => '0',
			'user_ip'             => '127.0.0.1',
			'user_login_attempts' => '0',
			'user_inactive_reason' => '0',
			'user_form_salt'      => 'salt',
			'user_actkey'         => '',
			'token_generation'    => '7',
			'perm_version'        => '4',
		];
	}

	private function makeRepository(\PDO $pdo): PdoUserRepository
	{
		return new PdoUserRepository($pdo);
	}

	#[Test]
	public function itHydratesTokenGenerationFromRow(): void
	{
		$row  = $this->baseRow();
		$stmt = $this->createMock(\PDOStatement::class);
		$stmt->method('execute')->willReturn(true);
		$stmt->method('fetch')->willReturn($row);

		$pdo = $this->createMock(\PDO::class);
		$pdo->method('prepare')->willReturn($stmt);

		$repo = $this->makeRepository($pdo);
		$user = $repo->findById(42);

		self::assertNotNull($user);
		self::assertSame(7, $user->tokenGeneration);
	}

	#[Test]
	public function itHydratesTokenGenerationDefaultsToZeroWhenMissing(): void
	{
		$row = $this->baseRow();
		unset($row['token_generation']);

		$stmt = $this->createMock(\PDOStatement::class);
		$stmt->method('execute')->willReturn(true);
		$stmt->method('fetch')->willReturn($row);

		$pdo = $this->createMock(\PDO::class);
		$pdo->method('prepare')->willReturn($stmt);

		$repo = $this->makeRepository($pdo);
		$user = $repo->findById(42);

		self::assertNotNull($user);
		self::assertSame(0, $user->tokenGeneration);
	}

	#[Test]
	public function itIncrementsTokenGenerationWithPreparedStatement(): void
	{
		$expectedSql = 'UPDATE phpbb_users SET token_generation = token_generation + 1 WHERE user_id = :id';

		$stmt = $this->createMock(\PDOStatement::class);
		$stmt->expects(self::once())
			->method('execute')
			->with([':id' => 42])
			->willReturn(true);

		$pdo = $this->createMock(\PDO::class);
		$pdo->expects(self::once())
			->method('prepare')
			->with($expectedSql)
			->willReturn($stmt);

		$repo = $this->makeRepository($pdo);
		$repo->incrementTokenGeneration(42);
	}

	#[Test]
	public function itHydratesPermVersionFromRow(): void
	{
		$row  = $this->baseRow();
		$stmt = $this->createMock(\PDOStatement::class);
		$stmt->method('execute')->willReturn(true);
		$stmt->method('fetch')->willReturn($row);

		$pdo = $this->createMock(\PDO::class);
		$pdo->method('prepare')->willReturn($stmt);

		$repo = $this->makeRepository($pdo);
		$user = $repo->findById(42);

		self::assertNotNull($user);
		self::assertSame(4, $user->permVersion);
	}
}

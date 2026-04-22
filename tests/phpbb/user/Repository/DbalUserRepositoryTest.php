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

use phpbb\Tests\Integration\IntegrationTestCase;
use phpbb\user\DTO\PaginatedResult;
use phpbb\user\DTO\UserDisplayDTO;
use phpbb\user\DTO\UserSearchCriteria;
use phpbb\user\Entity\User;
use phpbb\user\Repository\DbalUserRepository;
use PHPUnit\Framework\Attributes\Test;

class DbalUserRepositoryTest extends IntegrationTestCase
{
	private DbalUserRepository $repository;

	protected function setUpSchema(): void
	{
		$this->connection->executeStatement(
			'CREATE TABLE phpbb_users (
				user_id              INTEGER PRIMARY KEY AUTOINCREMENT,
				user_type            INTEGER NOT NULL DEFAULT 0,
				username             TEXT    NOT NULL DEFAULT \'\',
				username_clean       TEXT    NOT NULL DEFAULT \'\',
				user_email           TEXT    NOT NULL DEFAULT \'\',
				user_password        TEXT    NOT NULL DEFAULT \'\',
				user_colour          TEXT    NOT NULL DEFAULT \'\',
				group_id             INTEGER NOT NULL DEFAULT 0,
				user_avatar          TEXT    NOT NULL DEFAULT \'\',
				user_regdate         INTEGER NOT NULL DEFAULT 0,
				user_lastmark        INTEGER NOT NULL DEFAULT 0,
				user_posts           INTEGER NOT NULL DEFAULT 0,
				user_lastpost_time   INTEGER NOT NULL DEFAULT 0,
				user_new             INTEGER NOT NULL DEFAULT 1,
				user_rank            INTEGER NOT NULL DEFAULT 0,
				user_ip              TEXT    NOT NULL DEFAULT \'\',
				user_login_attempts  INTEGER NOT NULL DEFAULT 0,
				user_inactive_reason INTEGER NOT NULL DEFAULT 0,
				user_form_salt       TEXT    NOT NULL DEFAULT \'\',
				user_actkey          TEXT    NOT NULL DEFAULT \'\',
				token_generation     INTEGER NOT NULL DEFAULT 0,
				perm_version         INTEGER NOT NULL DEFAULT 0
			)',
		);

		$this->repository = new DbalUserRepository($this->connection);
	}

	private function insertUser(array $overrides = []): int
	{
		$defaults = [
			'user_type'           => 0,
			'username'            => 'testuser',
			'username_clean'      => 'testuser',
			'user_email'          => 'test@example.com',
			'user_password'       => 'hash',
			'user_colour'         => '',
			'group_id'            => 2,
			'user_avatar'         => '',
			'user_regdate'        => time(),
			'user_lastmark'       => time(),
			'user_posts'          => 0,
			'user_lastpost_time'  => 0,
			'user_new'            => 1,
			'user_rank'           => 0,
			'user_ip'             => '127.0.0.1',
			'user_login_attempts' => 0,
			'user_inactive_reason' => 0,
			'user_form_salt'      => 'salt',
			'user_actkey'         => '',
			'token_generation'    => 0,
			'perm_version'        => 0,
		];

		$row          = array_merge($defaults, $overrides);
		$columns      = implode(', ', array_keys($row));
		$placeholders = implode(', ', array_map(fn ($k) => ':' . $k, array_keys($row)));

		$this->connection->executeStatement(
			"INSERT INTO phpbb_users ($columns) VALUES ($placeholders)",
			$row,
		);

		return (int) $this->connection->lastInsertId();
	}

	#[Test]
	public function testFindById_found_returnsHydratedUser(): void
	{
		$id = $this->insertUser(['username' => 'alice', 'username_clean' => 'alice', 'user_email' => 'alice@example.com']);

		$user = $this->repository->findById($id);

		$this->assertInstanceOf(User::class, $user);
		$this->assertSame($id, $user->id);
		$this->assertSame('alice', $user->username);
		$this->assertSame('alice', $user->usernameClean);
		$this->assertSame('alice@example.com', $user->email);
	}

	#[Test]
	public function testFindById_notFound_returnsNull(): void
	{
		$result = $this->repository->findById(99999);

		$this->assertNull($result);
	}

	#[Test]
	public function testFindByIds_returnsKeyedArray(): void
	{
		$id1 = $this->insertUser(['username' => 'user1', 'username_clean' => 'user1', 'user_email' => 'u1@example.com']);
		$id2 = $this->insertUser(['username' => 'user2', 'username_clean' => 'user2', 'user_email' => 'u2@example.com']);

		$result = $this->repository->findByIds([$id1, $id2]);

		$this->assertCount(2, $result);
		$this->assertArrayHasKey($id1, $result);
		$this->assertArrayHasKey($id2, $result);
		$this->assertInstanceOf(User::class, $result[$id1]);
		$this->assertInstanceOf(User::class, $result[$id2]);
	}

	#[Test]
	public function testFindByIds_emptyArray_returnsEmpty(): void
	{
		$result = $this->repository->findByIds([]);

		$this->assertSame([], $result);
	}

	#[Test]
	public function testFindDisplayByIds_returnsKeyedDtoArray(): void
	{
		$id = $this->insertUser(['username' => 'bob', 'username_clean' => 'bob', 'user_colour' => 'ff0000']);

		$result = $this->repository->findDisplayByIds([$id]);

		$this->assertCount(1, $result);
		$this->assertArrayHasKey($id, $result);
		$this->assertInstanceOf(UserDisplayDTO::class, $result[$id]);
		$this->assertSame('bob', $result[$id]->username);
		$this->assertSame('ff0000', $result[$id]->colour);
	}

	#[Test]
	public function testCreate_returnsHydratedUserWithId(): void
	{
		$data = [
			'type'           => 0,
			'username'       => 'newuser',
			'email'          => 'new@example.com',
			'passwordHash'   => 'securehash',
			'colour'         => '',
			'defaultGroupId' => 2,
			'avatarUrl'      => '',
			'registrationIp' => '192.168.1.1',
			'inactiveReason' => 0,
			'formSalt'       => 'abc123',
			'activationKey'  => '',
		];

		$user = $this->repository->create($data);

		$this->assertInstanceOf(User::class, $user);
		$this->assertGreaterThan(0, $user->id);

		$fetched = $this->repository->findById($user->id);
		$this->assertNotNull($fetched);
		$this->assertSame('newuser', $fetched->username);
	}

	#[Test]
	public function testUpdate_partialDataUpdatesColumn(): void
	{
		$id = $this->insertUser(['username' => 'oldname', 'username_clean' => 'oldname']);

		$this->repository->update($id, ['username' => 'new_name']);

		$user = $this->repository->findById($id);
		$this->assertNotNull($user);
		$this->assertSame('new_name', $user->username);
	}

	#[Test]
	public function testDelete_removesRow(): void
	{
		$id = $this->insertUser();

		$this->repository->delete($id);

		$this->assertNull($this->repository->findById($id));
	}

	#[Test]
	public function testSearch_pagination_returnsCorrectPage(): void
	{
		$this->insertUser(['username' => 'user_a', 'username_clean' => 'user_a', 'user_email' => 'ua@example.com']);
		$this->insertUser(['username' => 'user_b', 'username_clean' => 'user_b', 'user_email' => 'ub@example.com']);
		$this->insertUser(['username' => 'user_c', 'username_clean' => 'user_c', 'user_email' => 'uc@example.com']);
		$this->insertUser(['username' => 'user_d', 'username_clean' => 'user_d', 'user_email' => 'ud@example.com']);
		$this->insertUser(['username' => 'user_e', 'username_clean' => 'user_e', 'user_email' => 'ue@example.com']);

		$criteria = new UserSearchCriteria(page: 2, perPage: 2);
		$result   = $this->repository->search($criteria);

		$this->assertInstanceOf(PaginatedResult::class, $result);
		$this->assertCount(2, $result->items);
		$this->assertSame(5, $result->total);
		$this->assertSame(2, $result->page);
	}

	#[Test]
	public function testSearch_noResults_returnsEmptyResult(): void
	{
		$criteria = new UserSearchCriteria(query: 'nomatch_xyz_impossible');
		$result   = $this->repository->search($criteria);

		$this->assertSame([], $result->items);
		$this->assertSame(0, $result->total);
	}

	#[Test]
	public function testIncrementTokenGeneration_incrementsField(): void
	{
		$id = $this->insertUser(['token_generation' => 0]);

		$this->repository->incrementTokenGeneration($id);

		$user = $this->repository->findById($id);
		$this->assertNotNull($user);
		$this->assertSame(1, $user->tokenGeneration);
	}
}

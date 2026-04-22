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
use phpbb\user\Repository\DbalBanRepository;
use PHPUnit\Framework\Attributes\Test;

final class DbalBanRepositoryTest extends IntegrationTestCase
{
	private DbalBanRepository $repository;

	protected function setUpSchema(): void
	{
		$this->connection->executeStatement('
			CREATE TABLE phpbb_banlist (
				ban_id          INTEGER PRIMARY KEY AUTOINCREMENT,
				ban_userid      INTEGER NOT NULL DEFAULT 0,
				ban_ip          TEXT    NOT NULL DEFAULT \'\',
				ban_email       TEXT    NOT NULL DEFAULT \'\',
				ban_start       INTEGER NOT NULL DEFAULT 0,
				ban_end         INTEGER NOT NULL DEFAULT 0,
				ban_exclude     INTEGER NOT NULL DEFAULT 0,
				ban_reason      TEXT    NOT NULL DEFAULT \'\',
				ban_give_reason TEXT    NOT NULL DEFAULT \'\'
			)
		');

		$this->repository = new DbalBanRepository($this->connection);
	}

	private function insertBan(array $data = []): int
	{
		$defaults = [
			'ban_userid'      => 0,
			'ban_ip'          => '',
			'ban_email'       => '',
			'ban_start'       => time(),
			'ban_end'         => 0,
			'ban_exclude'     => 0,
			'ban_reason'      => '',
			'ban_give_reason' => '',
		];
		$row = array_merge($defaults, $data);

		$this->connection->executeStatement(
			'INSERT INTO phpbb_banlist
				(ban_userid, ban_ip, ban_email, ban_start, ban_end, ban_exclude, ban_reason, ban_give_reason)
			 VALUES
				(:ban_userid, :ban_ip, :ban_email, :ban_start, :ban_end, :ban_exclude, :ban_reason, :ban_give_reason)',
			$row,
		);

		return (int) $this->connection->lastInsertId();
	}

	#[Test]
	public function testIsUserBanned_returnsTrueForActiveBan(): void
	{
		$this->insertBan(['ban_userid' => 5, 'ban_end' => time() + 100000]);

		self::assertTrue($this->repository->isUserBanned(5));
	}

	#[Test]
	public function testIsUserBanned_returnsFalseForExpiredBan(): void
	{
		$this->insertBan(['ban_userid' => 7, 'ban_end' => time() - 1]);

		self::assertFalse($this->repository->isUserBanned(7));
	}

	#[Test]
	public function testIsIpBanned_returnsTrueForMatchingIp(): void
	{
		$this->insertBan(['ban_ip' => '1.2.3.4', 'ban_end' => time() + 100000]);

		self::assertTrue($this->repository->isIpBanned('1.2.3.4'));
	}

	#[Test]
	public function testIsEmailBanned_caseInsensitive(): void
	{
		$this->insertBan(['ban_email' => 'user@example.com', 'ban_end' => time() + 100000]);

		self::assertTrue($this->repository->isEmailBanned('User@Example.COM'));
	}

	#[Test]
	public function testFindById_found_returnsHydratedBan(): void
	{
		$id = $this->insertBan([
			'ban_userid'      => 10,
			'ban_ip'          => '',
			'ban_email'       => '',
			'ban_start'       => 1700000000,
			'ban_end'         => 1800000000,
			'ban_exclude'     => 0,
			'ban_reason'      => 'spam',
			'ban_give_reason' => 'You were banned for spam.',
		]);

		$ban = $this->repository->findById($id);

		self::assertNotNull($ban);
		self::assertSame($id, $ban->id);
		self::assertSame(10, $ban->userId);
		self::assertSame('spam', $ban->reason);
		self::assertSame('You were banned for spam.', $ban->displayReason);
		self::assertSame(1800000000, $ban->end?->getTimestamp());
	}

	#[Test]
	public function testFindById_notFound_returnsNull(): void
	{
		$result = $this->repository->findById(99999);

		self::assertNull($result);
	}

	#[Test]
	public function testFindAll_returnsAllBansOrderedByBanId(): void
	{
		$this->insertBan(['ban_userid' => 1]);
		$this->insertBan(['ban_userid' => 2]);
		$this->insertBan(['ban_userid' => 3]);

		$bans = $this->repository->findAll();

		self::assertCount(3, $bans);
		self::assertSame(1, $bans[0]->userId);
		self::assertSame(2, $bans[1]->userId);
		self::assertSame(3, $bans[2]->userId);
	}

	#[Test]
	public function testCreate_returnsHydratedBanWithId(): void
	{
		$ban = $this->repository->create([
			'userId'        => 20,
			'ip'            => '',
			'email'         => '',
			'end'           => null,
			'exclude'       => false,
			'reason'        => 'test reason',
			'displayReason' => 'public reason',
		]);

		self::assertGreaterThan(0, $ban->id);
		self::assertSame(20, $ban->userId);
		self::assertSame('test reason', $ban->reason);
		self::assertSame('public reason', $ban->displayReason);

		$refetched = $this->repository->findById($ban->id);
		self::assertNotNull($refetched);
		self::assertSame($ban->id, $refetched->id);
	}

	#[Test]
	public function testDelete_removesRow(): void
	{
		$id = $this->insertBan(['ban_userid' => 99]);

		$this->repository->delete($id);

		self::assertNull($this->repository->findById($id));
	}
}

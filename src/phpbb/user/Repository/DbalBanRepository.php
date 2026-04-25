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

namespace phpbb\user\Repository;

use phpbb\db\Exception\RepositoryException;
use phpbb\user\Contract\BanRepositoryInterface;
use phpbb\user\Entity\Ban;
use phpbb\user\Enum\BanType;

class DbalBanRepository implements BanRepositoryInterface
{
	private const TABLE = 'phpbb_banlist';

	public function __construct(
		private readonly \Doctrine\DBAL\Connection $connection,
	) {
	}

	public function isUserBanned(int $userId): bool
	{
		try {
			$qb = $this->connection->createQueryBuilder();

			return $qb->select('1')
				->from(self::TABLE)
				->where($qb->expr()->eq('ban_userid', ':userId'))
				->andWhere($qb->expr()->eq('ban_exclude', '0'))
				->andWhere('(ban_end = 0 OR ban_end > :now)')
				->setParameter('userId', $userId)
				->setParameter('now', time())
				->setMaxResults(1)
				->executeQuery()
				->fetchOne() !== false;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to check user ban.', previous: $e);
		}
	}

	public function isIpBanned(string $ip): bool
	{
		try {
			$qb = $this->connection->createQueryBuilder();

			return $qb->select('1')
				->from(self::TABLE)
				->where($qb->expr()->eq('ban_ip', ':ip'))
				->andWhere($qb->expr()->eq('ban_exclude', '0'))
				->andWhere('(ban_end = 0 OR ban_end > :now)')
				->setParameter('ip', $ip)
				->setParameter('now', time())
				->setMaxResults(1)
				->executeQuery()
				->fetchOne() !== false;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to check IP ban.', previous: $e);
		}
	}

	public function isEmailBanned(string $email): bool
	{
		try {
			$qb = $this->connection->createQueryBuilder();

			return $qb->select('1')
				->from(self::TABLE)
				->where($qb->expr()->eq('ban_email', ':email'))
				->andWhere($qb->expr()->eq('ban_exclude', '0'))
				->andWhere('(ban_end = 0 OR ban_end > :now)')
				->setParameter('email', mb_strtolower($email))
				->setParameter('now', time())
				->setMaxResults(1)
				->executeQuery()
				->fetchOne() !== false;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to check email ban.', previous: $e);
		}
	}

	public function findById(int $id): ?Ban
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$row = $qb->select('*')
				->from(self::TABLE)
				->where($qb->expr()->eq('ban_id', ':id'))
				->setParameter('id', $id)
				->setMaxResults(1)
				->executeQuery()
				->fetchAssociative();

			return $row !== false ? $this->hydrate($row) : null;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find ban by id.', previous: $e);
		}
	}

	public function findAll(): array
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$rows = $qb->select('*')
				->from(self::TABLE)
				->orderBy('ban_id', 'ASC')
				->executeQuery()
				->fetchAllAssociative();

			return array_map([$this, 'hydrate'], $rows);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to fetch all bans.', previous: $e);
		}
	}

	public function create(array $data): Ban
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$qb->insert(self::TABLE)
				->values([
					'ban_userid'      => ':userId',
					'ban_ip'          => ':ip',
					'ban_email'       => ':email',
					'ban_start'       => ':start',
					'ban_end'         => ':end',
					'ban_exclude'     => ':exclude',
					'ban_reason'      => ':reason',
					'ban_give_reason' => ':displayReason',
				])
				->setParameter('userId', $data['userId'] ?? 0)
				->setParameter('ip', $data['ip'] ?? '')
				->setParameter('email', isset($data['email']) ? mb_strtolower((string) $data['email']) : '')
				->setParameter('start', time())
				->setParameter('end', isset($data['end']) ? $data['end']->getTimestamp() : 0)
				->setParameter('exclude', (int) ($data['exclude'] ?? false))
				->setParameter('reason', $data['reason'] ?? '')
				->setParameter('displayReason', $data['displayReason'] ?? '')
				->executeStatement();

			$newId = (int) $this->connection->lastInsertId();

			return $this->findById($newId) ?? throw new \RuntimeException('Ban not found after INSERT');
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to create ban.', previous: $e);
		}
	}

	public function delete(int $id): void
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$qb->delete(self::TABLE)
				->where($qb->expr()->eq('ban_id', ':id'))
				->setParameter('id', $id)
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to delete ban.', previous: $e);
		}
	}

	/** @param array<string, mixed> $row */
	private function hydrate(array $row): Ban
	{
		$banEndTs  = (int) $row['ban_end'];
		$banEnd    = $banEndTs > 0
			? (new \DateTimeImmutable())->setTimestamp($banEndTs)
			: null;

		$banType = BanType::User;
		if (!empty($row['ban_ip'])) {
			$banType = BanType::Ip;
		} elseif (!empty($row['ban_email'])) {
			$banType = BanType::Email;
		}

		return new Ban(
			id: (int) $row['ban_id'],
			banType: $banType,
			userId: (int) $row['ban_userid'],
			ip: $row['ban_ip'],
			email: $row['ban_email'],
			start: (new \DateTimeImmutable())->setTimestamp((int) $row['ban_start']),
			end: $banEnd,
			exclude: (bool) $row['ban_exclude'],
			reason: $row['ban_reason'],
			displayReason: $row['ban_give_reason'],
		);
	}
}

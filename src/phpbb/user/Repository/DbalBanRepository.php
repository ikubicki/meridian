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
			$sql = 'SELECT 1 FROM ' . self::TABLE .
				' WHERE ban_userid = :userId AND ban_exclude = 0
				  AND (ban_end = 0 OR ban_end > :now) LIMIT 1';

			return $this->connection->executeQuery($sql, ['userId' => $userId, 'now' => time()])->fetchOne() !== false;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to check user ban.', previous: $e);
		}
	}

	public function isIpBanned(string $ip): bool
	{
		try {
			$sql = 'SELECT 1 FROM ' . self::TABLE .
				' WHERE ban_ip = :ip AND ban_exclude = 0
				  AND (ban_end = 0 OR ban_end > :now) LIMIT 1';

			return $this->connection->executeQuery($sql, ['ip' => $ip, 'now' => time()])->fetchOne() !== false;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to check IP ban.', previous: $e);
		}
	}

	public function isEmailBanned(string $email): bool
	{
		try {
			$sql = 'SELECT 1 FROM ' . self::TABLE .
				' WHERE ban_email = :email AND ban_exclude = 0
				  AND (ban_end = 0 OR ban_end > :now) LIMIT 1';

			return $this->connection->executeQuery($sql, ['email' => mb_strtolower($email), 'now' => time()])->fetchOne() !== false;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to check email ban.', previous: $e);
		}
	}

	public function findById(int $id): ?Ban
	{
		try {
			$sql = 'SELECT * FROM ' . self::TABLE . ' WHERE ban_id = :id LIMIT 1';
			$row = $this->connection->executeQuery($sql, ['id' => $id])->fetchAssociative();

			return $row !== false ? $this->hydrate($row) : null;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find ban by id.', previous: $e);
		}
	}

	public function findAll(): array
	{
		try {
			$rows = $this->connection->executeQuery('SELECT * FROM ' . self::TABLE . ' ORDER BY ban_id ASC')->fetchAllAssociative();

			return array_map([$this, 'hydrate'], $rows);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to fetch all bans.', previous: $e);
		}
	}

	public function create(array $data): Ban
	{
		try {
			$sql = 'INSERT INTO ' . self::TABLE .
				' (ban_userid, ban_ip, ban_email, ban_start, ban_end,
				   ban_exclude, ban_reason, ban_give_reason)
				  VALUES (:userId, :ip, :email, :start, :end,
				   :exclude, :reason, :displayReason)';
			$this->connection->executeStatement($sql, [
				'userId'        => $data['userId'] ?? 0,
				'ip'            => $data['ip'] ?? '',
				'email'         => isset($data['email']) ? mb_strtolower((string) $data['email']) : '',
				'start'         => time(),
				'end'           => isset($data['end']) ? $data['end']->getTimestamp() : 0,
				'exclude'       => (int) ($data['exclude'] ?? false),
				'reason'        => $data['reason'] ?? '',
				'displayReason' => $data['displayReason'] ?? '',
			]);
			$newId = (int) $this->connection->lastInsertId();

			return $this->findById($newId) ?? throw new \RuntimeException('Ban not found after INSERT');
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to create ban.', previous: $e);
		}
	}

	public function delete(int $id): void
	{
		try {
			$this->connection->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE ban_id = :id', ['id' => $id]);
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

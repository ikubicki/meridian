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

use phpbb\user\Contract\BanRepositoryInterface;
use phpbb\user\Entity\Ban;
use phpbb\user\Enum\BanType;

class PdoBanRepository implements BanRepositoryInterface
{
	private const TABLE = 'phpbb_banlist';

	public function __construct(
		private readonly \PDO $pdo,
	) {
	}

	public function isUserBanned(int $userId): bool
	{
		$now  = time();
		$stmt = $this->pdo->prepare(
			'SELECT 1 FROM ' . self::TABLE .
			' WHERE ban_userid = :userId AND ban_exclude = 0
			  AND (ban_end = 0 OR ban_end > :now) LIMIT 1',
		);
		$stmt->execute([':userId' => $userId, ':now' => $now]);

		return $stmt->fetchColumn() !== false;
	}

	public function isIpBanned(string $ip): bool
	{
		$now  = time();
		$stmt = $this->pdo->prepare(
			'SELECT 1 FROM ' . self::TABLE .
			' WHERE ban_ip = :ip AND ban_exclude = 0
			  AND (ban_end = 0 OR ban_end > :now) LIMIT 1',
		);
		$stmt->execute([':ip' => $ip, ':now' => $now]);

		return $stmt->fetchColumn() !== false;
	}

	public function isEmailBanned(string $email): bool
	{
		$now  = time();
		$stmt = $this->pdo->prepare(
			'SELECT 1 FROM ' . self::TABLE .
			' WHERE ban_email = :email AND ban_exclude = 0
			  AND (ban_end = 0 OR ban_end > :now) LIMIT 1',
		);
		$stmt->execute([':email' => mb_strtolower($email), ':now' => $now]);

		return $stmt->fetchColumn() !== false;
	}

	public function findById(int $id): ?Ban
	{
		$stmt = $this->pdo->prepare(
			'SELECT * FROM ' . self::TABLE . ' WHERE ban_id = :id LIMIT 1',
		);
		$stmt->execute([':id' => $id]);
		$row = $stmt->fetch();

		return $row !== false ? $this->hydrate($row) : null;
	}

	public function findAll(): array
	{
		$stmt = $this->pdo->query('SELECT * FROM ' . self::TABLE . ' ORDER BY ban_id ASC');

		return array_map([$this, 'hydrate'], $stmt->fetchAll());
	}

	public function create(array $data): Ban
	{
		$stmt = $this->pdo->prepare(
			'INSERT INTO ' . self::TABLE .
			' (ban_userid, ban_ip, ban_email, ban_start, ban_end,
			   ban_exclude, ban_reason, ban_give_reason)
			  VALUES (:userId, :ip, :email, :start, :end,
			   :exclude, :reason, :displayReason)',
		);

		$stmt->execute([
			':userId'        => $data['userId'] ?? 0,
			':ip'            => $data['ip'] ?? '',
			':email'         => isset($data['email']) ? mb_strtolower((string) $data['email']) : '',
			':start'         => time(),
			':end'           => isset($data['end']) ? $data['end']->getTimestamp() : 0,
			':exclude'       => (int) ($data['exclude'] ?? false),
			':reason'        => $data['reason'] ?? '',
			':displayReason' => $data['displayReason'] ?? '',
		]);

		$newId = (int) $this->pdo->lastInsertId();

		return $this->findById($newId) ?? throw new \RuntimeException('Failed to retrieve newly created ban.');
	}

	public function delete(int $id): void
	{
		$stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE ban_id = :id');
		$stmt->execute([':id' => $id]);
	}

	/** @param array<string, mixed> $row */
	private function hydrate(array $row): Ban
	{
		$banEndTs  = (int) $row['ban_end'];
		$banEnd    = $banEndTs > 0
			? (new \DateTimeImmutable())->setTimestamp($banEndTs)
			: null;

		$banType = BanType::User;
		if (!empty($row['ban_ip']))
		{
			$banType = BanType::Ip;
		}
		elseif (!empty($row['ban_email']))
		{
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

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

use phpbb\user\Contract\UserRepositoryInterface;
use phpbb\user\DTO\PaginatedResult;
use phpbb\user\DTO\UserDisplayDTO;
use phpbb\user\DTO\UserSearchCriteria;
use phpbb\user\Entity\User;
use phpbb\user\Enum\InactiveReason;
use phpbb\user\Enum\UserType;

class PdoUserRepository implements UserRepositoryInterface
{
	private const TABLE = 'phpbb_users';

	public function __construct(
		private readonly \PDO $pdo,
	) {
	}

	public function findById(int $id): ?User
	{
		$stmt = $this->pdo->prepare(
			'SELECT * FROM ' . self::TABLE . ' WHERE user_id = :id LIMIT 1',
		);
		$stmt->execute([':id' => $id]);
		$row = $stmt->fetch();

		return $row !== false ? $this->hydrate($row) : null;
	}

	public function findByIds(array $ids): array
	{
		if ($ids === [])
		{
			return [];
		}

		$placeholders = implode(',', array_fill(0, count($ids), '?'));
		$stmt = $this->pdo->prepare(
			'SELECT * FROM ' . self::TABLE . ' WHERE user_id IN (' . $placeholders . ')',
		);
		$stmt->execute($ids);

		$result = [];
		foreach ($stmt->fetchAll() as $row)
		{
			$user = $this->hydrate($row);
			$result[$user->id] = $user;
		}

		return $result;
	}

	public function findByUsername(string $username): ?User
	{
		$stmt = $this->pdo->prepare(
			'SELECT * FROM ' . self::TABLE . ' WHERE username_clean = :clean LIMIT 1',
		);
		$stmt->execute([':clean' => mb_strtolower($username)]);
		$row = $stmt->fetch();

		return $row !== false ? $this->hydrate($row) : null;
	}

	public function findByEmail(string $email): ?User
	{
		$stmt = $this->pdo->prepare(
			'SELECT * FROM ' . self::TABLE . ' WHERE user_email = :email LIMIT 1',
		);
		$stmt->execute([':email' => mb_strtolower($email)]);
		$row = $stmt->fetch();

		return $row !== false ? $this->hydrate($row) : null;
	}

	public function create(array $data): User
	{
		$stmt = $this->pdo->prepare(
			'INSERT INTO ' . self::TABLE . '
			(user_type, username, username_clean, user_email, user_password,
			 user_colour, group_id, user_avatar, user_regdate, user_lastmark,
			 user_posts, user_new, user_rank, user_ip, user_login_attempts,
			 user_inactive_reason, user_form_salt, user_actkey)
			VALUES
			(:type, :username, :usernameClean, :email, :passwordHash,
			 :colour, :defaultGroupId, :avatarUrl, :registeredAt, :lastmark,
			 :posts, :isNew, :rank, :registrationIp, :loginAttempts,
			 :inactiveReason, :formSalt, :activationKey)',
		);

		$stmt->execute([
			':type'           => $data['type'] instanceof UserType ? $data['type']->value : (int) $data['type'],
			':username'       => $data['username'],
			':usernameClean'  => mb_strtolower((string) $data['username']),
			':email'          => mb_strtolower((string) $data['email']),
			':passwordHash'   => $data['passwordHash'] ?? '',
			':colour'         => $data['colour'] ?? '',
			':defaultGroupId' => $data['defaultGroupId'] ?? 0,
			':avatarUrl'      => $data['avatarUrl'] ?? '',
			':registeredAt'   => time(),
			':lastmark'       => time(),
			':posts'          => 0,
			':isNew'          => 1,
			':rank'           => 0,
			':registrationIp' => $data['registrationIp'] ?? '',
			':loginAttempts'  => 0,
			':inactiveReason' => isset($data['inactiveReason'])
				? ($data['inactiveReason'] instanceof InactiveReason ? $data['inactiveReason']->value : (int) $data['inactiveReason'])
				: 0,
			':formSalt'       => $data['formSalt'] ?? '',
			':activationKey'  => $data['activationKey'] ?? '',
		]);

		$newId = (int) $this->pdo->lastInsertId();

		return $this->findById($newId) ?? throw new \RuntimeException('Failed to retrieve newly created user.');
	}

	public function update(int $id, array $data): void
	{
		if ($data === [])
		{
			return;
		}

		$allowedColumns = [
			'username'       => 'username',
			'usernameClean'  => 'username_clean',
			'email'          => 'user_email',
			'passwordHash'   => 'user_password',
			'colour'         => 'user_colour',
			'avatarUrl'      => 'user_avatar',
			'loginAttempts'  => 'user_login_attempts',
			'inactiveReason' => 'user_inactive_reason',
			'activationKey'  => 'user_actkey',
			'type'           => 'user_type',
		];

		$setClauses = [];
		$params     = [':id' => $id];

		foreach ($data as $field => $value)
		{
			if (!isset($allowedColumns[$field]))
			{
				continue;
			}

			$column           = $allowedColumns[$field];
			$placeholder      = ':' . $field;
			$setClauses[]     = $column . ' = ' . $placeholder;

			if ($value instanceof UserType)
			{
				$params[$placeholder] = $value->value;
			}
			elseif ($value instanceof InactiveReason)
			{
				$params[$placeholder] = $value->value;
			}
			else
			{
				$params[$placeholder] = $value;
			}
		}

		if ($setClauses === [])
		{
			return;
		}

		$sql = 'UPDATE ' . self::TABLE . ' SET ' . implode(', ', $setClauses) . ' WHERE user_id = :id';
		$this->pdo->prepare($sql)->execute($params);
	}

	public function delete(int $id): void
	{
		$stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE user_id = :id');
		$stmt->execute([':id' => $id]);
	}

	public function search(UserSearchCriteria $criteria): PaginatedResult
	{
		$where  = ['1=1'];
		$params = [];

		if ($criteria->query !== null)
		{
			$where[]          = 'username_clean LIKE :query';
			$params[':query'] = '%' . mb_strtolower($criteria->query) . '%';
		}

		if ($criteria->type !== null)
		{
			$where[]         = 'user_type = :type';
			$params[':type'] = $criteria->type->value;
		}

		if ($criteria->groupId !== null)
		{
			$where[]           = 'group_id = :groupId';
			$params[':groupId'] = $criteria->groupId;
		}

		$allowedSorts  = ['username', 'user_posts', 'user_regdate'];
		$sortColumn    = in_array($criteria->sort, $allowedSorts, true) ? $criteria->sort : 'username';
		$sortDirection = strtoupper($criteria->sortOrder) === 'DESC' ? 'DESC' : 'ASC';

		$whereClause = implode(' AND ', $where);

		$countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE ' . $whereClause);
		$countStmt->execute($params);
		$total = (int) $countStmt->fetchColumn();

		$offset             = ($criteria->page - 1) * $criteria->perPage;
		$params[':limit']   = $criteria->perPage;
		$params[':offset']  = $offset;

		$stmt = $this->pdo->prepare(
			'SELECT * FROM ' . self::TABLE . ' WHERE ' . $whereClause .
			' ORDER BY ' . $sortColumn . ' ' . $sortDirection .
			' LIMIT :limit OFFSET :offset',
		);
		$stmt->bindValue(':limit', $criteria->perPage, \PDO::PARAM_INT);
		$stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);

		foreach ($params as $key => $value)
		{
			if ($key !== ':limit' && $key !== ':offset')
			{
				$stmt->bindValue($key, $value);
			}
		}

		$stmt->execute();

		$items = array_map([$this, 'hydrate'], $stmt->fetchAll());

		return new PaginatedResult($items, $total, $criteria->page, $criteria->perPage);
	}

	public function findDisplayByIds(array $ids): array
	{
		if ($ids === [])
		{
			return [];
		}

		$placeholders = implode(',', array_fill(0, count($ids), '?'));
		$stmt = $this->pdo->prepare(
			'SELECT user_id, username, user_colour, user_avatar FROM ' . self::TABLE .
			' WHERE user_id IN (' . $placeholders . ')',
		);
		$stmt->execute($ids);

		$result = [];
		foreach ($stmt->fetchAll() as $row)
		{
			$dto = new UserDisplayDTO(
				id: (int) $row['user_id'],
				username: $row['username'],
				colour: $row['user_colour'],
				avatarUrl: $row['user_avatar'],
			);
			$result[$dto->id] = $dto;
		}

		return $result;
	}

	/** @param array<string, mixed> $row */
	private function hydrate(array $row): User
	{
		$lastPostTime = isset($row['user_lastpost_time']) && $row['user_lastpost_time'] > 0
			? (new \DateTimeImmutable())->setTimestamp((int) $row['user_lastpost_time'])
			: null;

		$inactiveReasonValue = (int) ($row['user_inactive_reason'] ?? 0);
		$inactiveReason      = $inactiveReasonValue > 0 ? InactiveReason::tryFrom($inactiveReasonValue) : null;

		return new User(
			id: (int) $row['user_id'],
			type: UserType::from((int) $row['user_type']),
			username: $row['username'],
			usernameClean: $row['username_clean'],
			email: $row['user_email'],
			passwordHash: $row['user_password'],
			colour: $row['user_colour'],
			defaultGroupId: (int) $row['group_id'],
			avatarUrl: $row['user_avatar'],
			registeredAt: (new \DateTimeImmutable())->setTimestamp((int) $row['user_regdate']),
			lastmark: (new \DateTimeImmutable())->setTimestamp((int) $row['user_lastmark']),
			posts: (int) $row['user_posts'],
			lastPostTime: $lastPostTime,
			isNew: (bool) $row['user_new'],
			rank: (int) $row['user_rank'],
			registrationIp: $row['user_ip'],
			loginAttempts: (int) $row['user_login_attempts'],
			inactiveReason: $inactiveReason,
			formSalt: $row['user_form_salt'],
			activationKey: $row['user_actkey'],
		);
	}
}

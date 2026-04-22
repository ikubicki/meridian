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

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use phpbb\db\Exception\RepositoryException;
use phpbb\user\Contract\UserRepositoryInterface;
use phpbb\user\DTO\PaginatedResult;
use phpbb\user\DTO\UserDisplayDTO;
use phpbb\user\DTO\UserSearchCriteria;
use phpbb\user\Entity\User;
use phpbb\user\Enum\InactiveReason;
use phpbb\user\Enum\UserType;

class DbalUserRepository implements UserRepositoryInterface
{
	private const TABLE = 'phpbb_users';

	public function __construct(
		private readonly Connection $connection,
	) {
	}

	public function findById(int $id): ?User
	{
		try {
			$row = $this->connection->executeQuery(
				'SELECT * FROM ' . self::TABLE . ' WHERE user_id = :id LIMIT 1',
				['id' => $id],
			)->fetchAssociative();

			return $row !== false ? $this->hydrate($row) : null;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to fetch user by id', previous: $e);
		}
	}

	public function findByIds(array $ids): array
	{
		if ($ids === []) {
			return [];
		}

		try {
			$rows = $this->connection->executeQuery(
				'SELECT * FROM ' . self::TABLE . ' WHERE user_id IN (?)',
				[$ids],
				[ArrayParameterType::INTEGER],
			)->fetchAllAssociative();

			$result = [];
			foreach ($rows as $row) {
				$user           = $this->hydrate($row);
				$result[$user->id] = $user;
			}

			return $result;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to fetch users by ids', previous: $e);
		}
	}

	public function findByUsername(string $username): ?User
	{
		try {
			$row = $this->connection->executeQuery(
				'SELECT * FROM ' . self::TABLE . ' WHERE username_clean = :clean LIMIT 1',
				['clean' => mb_strtolower($username)],
			)->fetchAssociative();

			return $row !== false ? $this->hydrate($row) : null;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to fetch user by username', previous: $e);
		}
	}

	public function findByEmail(string $email): ?User
	{
		try {
			$row = $this->connection->executeQuery(
				'SELECT * FROM ' . self::TABLE . ' WHERE user_email = :email LIMIT 1',
				['email' => mb_strtolower($email)],
			)->fetchAssociative();

			return $row !== false ? $this->hydrate($row) : null;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to fetch user by email', previous: $e);
		}
	}

	public function create(array $data): User
	{
		try {
			$this->connection->executeStatement(
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
				[
					'type'           => $data['type'] instanceof UserType ? $data['type']->value : (int) $data['type'],
					'username'       => $data['username'],
					'usernameClean'  => mb_strtolower((string) $data['username']),
					'email'          => mb_strtolower((string) $data['email']),
					'passwordHash'   => $data['passwordHash'] ?? '',
					'colour'         => $data['colour'] ?? '',
					'defaultGroupId' => $data['defaultGroupId'] ?? 0,
					'avatarUrl'      => $data['avatarUrl'] ?? '',
					'registeredAt'   => time(),
					'lastmark'       => time(),
					'posts'          => 0,
					'isNew'          => 1,
					'rank'           => 0,
					'registrationIp' => $data['registrationIp'] ?? '',
					'loginAttempts'  => 0,
					'inactiveReason' => isset($data['inactiveReason'])
						? ($data['inactiveReason'] instanceof InactiveReason ? $data['inactiveReason']->value : (int) $data['inactiveReason'])
						: 0,
					'formSalt'       => $data['formSalt'] ?? '',
					'activationKey'  => $data['activationKey'] ?? '',
				],
			);

			$newId = (int) $this->connection->lastInsertId();

			return $this->findById($newId) ?? throw new \RuntimeException('User not found after INSERT');
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to create user', previous: $e);
		}
	}

	public function update(int $id, array $data): void
	{
		if ($data === []) {
			return;
		}

		try {
			$allowedColumns = [
				'username'        => 'username',
				'usernameClean'   => 'username_clean',
				'email'           => 'user_email',
				'passwordHash'    => 'user_password',
				'colour'          => 'user_colour',
				'avatarUrl'       => 'user_avatar',
				'loginAttempts'   => 'user_login_attempts',
				'inactiveReason'  => 'user_inactive_reason',
				'activationKey'   => 'user_actkey',
				'type'            => 'user_type',
				'tokenGeneration' => 'token_generation',
				'permVersion'     => 'perm_version',
			];

			$qb       = $this->connection->createQueryBuilder()
				->update(self::TABLE)
				->where('user_id = :id')
				->setParameter('id', $id);
			$setCount = 0;

			foreach ($data as $field => $value) {
				if (!isset($allowedColumns[$field])) {
					continue;
				}

				$column = $allowedColumns[$field];

				if ($value instanceof UserType) {
					$value = $value->value;
				} elseif ($value instanceof InactiveReason) {
					$value = $value->value;
				}

				$qb->set($column, ':' . $field)->setParameter($field, $value);
				$setCount++;
			}

			if ($setCount === 0) {
				return;
			}

			$qb->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to update user', previous: $e);
		}
	}

	public function delete(int $id): void
	{
		try {
			$this->connection->executeStatement(
				'DELETE FROM ' . self::TABLE . ' WHERE user_id = :id',
				['id' => $id],
			);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to delete user', previous: $e);
		}
	}

	public function search(UserSearchCriteria $criteria): PaginatedResult
	{
		try {
			$qb = $this->connection->createQueryBuilder()
				->select('*')
				->from(self::TABLE);

			if ($criteria->query !== null) {
				$qb->andWhere('username_clean LIKE :query')
					->setParameter('query', '%' . mb_strtolower($criteria->query) . '%');
			}

			if ($criteria->type !== null) {
				$qb->andWhere('user_type = :type')
					->setParameter('type', $criteria->type->value);
			}

			if ($criteria->groupId !== null) {
				$qb->andWhere('group_id = :groupId')
					->setParameter('groupId', $criteria->groupId);
			}

			$countQb = clone $qb;
			$total   = (int) $countQb->select('COUNT(*)')->executeQuery()->fetchOne();

			$allowedSorts  = ['username', 'user_posts', 'user_regdate'];
			$sortColumn    = in_array($criteria->sort, $allowedSorts, true) ? $criteria->sort : 'username';
			$sortDirection = strtoupper($criteria->sortOrder) === 'DESC' ? 'DESC' : 'ASC';

			$rows = $qb->select('*')
				->orderBy($sortColumn, $sortDirection)
				->setMaxResults($criteria->perPage)
				->setFirstResult(($criteria->page - 1) * $criteria->perPage)
				->executeQuery()
				->fetchAllAssociative();

			$items = array_map([$this, 'hydrate'], $rows);

			return new PaginatedResult($items, $total, $criteria->page, $criteria->perPage);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to search users', previous: $e);
		}
	}

	public function findDisplayByIds(array $ids): array
	{
		if ($ids === []) {
			return [];
		}

		try {
			$rows = $this->connection->executeQuery(
				'SELECT user_id, username, user_colour, user_avatar FROM ' . self::TABLE . ' WHERE user_id IN (?)',
				[$ids],
				[ArrayParameterType::INTEGER],
			)->fetchAllAssociative();

			$result = [];
			foreach ($rows as $row) {
				$dto = new UserDisplayDTO(
					id: (int) $row['user_id'],
					username: $row['username'],
					colour: $row['user_colour'],
					avatarUrl: $row['user_avatar'],
				);
				$result[$dto->id] = $dto;
			}

			return $result;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to fetch user display data by ids', previous: $e);
		}
	}

	public function incrementTokenGeneration(int $userId): void
	{
		try {
			$this->connection->executeStatement(
				'UPDATE ' . self::TABLE . ' SET token_generation = token_generation + 1 WHERE user_id = :id',
				['id' => $userId],
			);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to increment token generation', previous: $e);
		}
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
			tokenGeneration: (int) ($row['token_generation'] ?? 0),
			permVersion: (int) ($row['perm_version'] ?? 0),
		);
	}
}

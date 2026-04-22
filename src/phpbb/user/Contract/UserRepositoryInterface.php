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

namespace phpbb\user\Contract;

use phpbb\user\DTO\PaginatedResult;
use phpbb\user\DTO\UserDisplayDTO;
use phpbb\user\DTO\UserSearchCriteria;
use phpbb\user\Entity\User;

interface UserRepositoryInterface
{
	public function findById(int $id): ?User;

	/**
	 * @param list<int> $ids
	 * @return array<int, User> keyed by user id
	 */
	public function findByIds(array $ids): array;

	public function findByUsername(string $username): ?User;

	public function findByEmail(string $email): ?User;

	/**
	 * @param array<string, mixed> $data
	 */
	public function create(array $data): User;

	/**
	 * @param array<string, mixed> $data
	 */
	public function update(int $id, array $data): void;

	public function delete(int $id): void;

	/**
	 * @return PaginatedResult<User>
	 */
	public function search(UserSearchCriteria $criteria): PaginatedResult;

	/**
	 * @param list<int> $ids
	 * @return array<int, UserDisplayDTO> keyed by user id
	 */
	public function findDisplayByIds(array $ids): array;

	public function incrementTokenGeneration(int $userId): void;
}

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

namespace phpbb\user\Service;

use phpbb\user\Contract\UserRepositoryInterface;
use phpbb\user\DTO\PaginatedResult;
use phpbb\user\DTO\UserSearchCriteria;
use phpbb\user\Entity\User;

/**
 * Primary read-facing entry point for user lookups.
 *
 * Delegates all persistence work to the repository; adds no state of its own.
 */
class UserSearchService
{
	public function __construct(
		private readonly UserRepositoryInterface $userRepository,
	) {
	}

	public function findById(int $id): ?User
	{
		return $this->userRepository->findById($id);
	}

	public function findByUsername(string $username): ?User
	{
		return $this->userRepository->findByUsername($username);
	}

	public function findByEmail(string $email): ?User
	{
		return $this->userRepository->findByEmail($email);
	}

	/**
	 * @return PaginatedResult<User>
	 */
	public function search(UserSearchCriteria $criteria): PaginatedResult
	{
		return $this->userRepository->search($criteria);
	}
}

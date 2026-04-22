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

use phpbb\user\Entity\Ban;

interface BanRepositoryInterface
{
	public function isUserBanned(int $userId): bool;

	public function isIpBanned(string $ip): bool;

	public function isEmailBanned(string $email): bool;

	public function findById(int $id): ?Ban;

	/**
	 * @return list<Ban>
	 */
	public function findAll(): array;

	/**
	 * @param array<string, mixed> $data
	 */
	public function create(array $data): Ban;

	public function delete(int $id): void;
}

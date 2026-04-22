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

use phpbb\cache\CachePoolFactoryInterface;
use phpbb\user\Contract\UserRepositoryInterface;
use phpbb\user\DTO\UserDisplayDTO;

/**
 * Returns lightweight UserDisplayDTO instances, backed by a cache pool
 * named "user_display".
 *
 * Cache entries are individual keys per user id with a 10-minute TTL.
 * Callers requesting N IDs get batched into a single repository call for
 * any IDs that are not yet cached.
 */
class UserDisplayService
{
	private const POOL_NAMESPACE = 'user_display';
	private const TTL            = 600; // 10 minutes

	public function __construct(
		private readonly UserRepositoryInterface $userRepository,
		private readonly CachePoolFactoryInterface $cachePoolFactory,
	) {
	}

	/**
	 * @param list<int>             $ids
	 * @return array<int, UserDisplayDTO> keyed by user id
	 */
	public function findDisplayByIds(array $ids): array
	{
		if ($ids === []) {
			return [];
		}

		$pool   = $this->cachePoolFactory->getPool(self::POOL_NAMESPACE);
		$result = [];
		$missed = [];

		foreach ($ids as $id) {
			$cached = $pool->get((string) $id);
			if ($cached !== null) {
				$result[$id] = $cached;
			} else {
				$missed[] = $id;
			}
		}

		if ($missed === []) {
			return $result;
		}

		$fresh = $this->userRepository->findDisplayByIds($missed);

		foreach ($fresh as $id => $dto) {
			$pool->set((string) $id, $dto, self::TTL);
			$result[$id] = $dto;
		}

		return $result;
	}
}

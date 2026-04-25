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

namespace phpbb\storage\Quota;

use Doctrine\DBAL\Connection;
use phpbb\common\Event\DomainEventCollection;
use phpbb\storage\Contract\QuotaServiceInterface;
use phpbb\storage\Contract\StorageQuotaRepositoryInterface;
use phpbb\storage\Event\QuotaExceededEvent;
use phpbb\storage\Event\QuotaReconciledEvent;
use phpbb\storage\Exception\QuotaExceededException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class QuotaService implements QuotaServiceInterface
{
	public function __construct(
		private readonly StorageQuotaRepositoryInterface $quotaRepo,
		private readonly Connection $connection,
		private readonly EventDispatcherInterface $dispatcher,
	) {
	}

	public function checkAndReserve(int $userId, int $forumId, int $bytes): void
	{
		$reserved = $this->quotaRepo->incrementUsage($userId, $forumId, $bytes);

		if ($reserved) {
			return;
		}

		// Distinguish: missing row vs. quota full
		$quota = $this->quotaRepo->findByUserAndForum($userId, $forumId);
		if ($quota === null) {
			// New user — insert unlimited default and retry once
			$this->quotaRepo->initDefault($userId, $forumId);
			$reserved = $this->quotaRepo->incrementUsage($userId, $forumId, $bytes);
			if ($reserved) {
				return;
			}
		}

		// Quota full (or retry still failed)
		$quota ??= $this->quotaRepo->findByUserAndForum($userId, $forumId);
		$this->dispatcher->dispatch(new QuotaExceededEvent(
			$userId,
			$userId,
			$forumId,
			$bytes,
			$quota?->maxBytes ?? 0,
		));

		throw new QuotaExceededException('Storage quota exceeded for user ' . $userId);
	}

	public function release(int $userId, int $forumId, int $bytes): void
	{
		$this->quotaRepo->decrementUsage($userId, $forumId, $bytes);
	}

	public function reconcileAll(): DomainEventCollection
	{
		$pairs = $this->quotaRepo->findAllUserForumPairs();
		$events = [];

		foreach ($pairs as $pair) {
			$userId  = (int) $pair['user_id'];
			$forumId = (int) $pair['forum_id'];

			$qb = $this->connection->createQueryBuilder();
			$actual = (int) $qb->select('COALESCE(SUM(filesize), 0)')
				->from('phpbb_stored_files')
				->where($qb->expr()->eq('uploader_id', ':uid'))
				->andWhere($qb->expr()->eq('forum_id', ':fid'))
				->setParameter('uid', $userId)
				->setParameter('fid', $forumId)
				->executeQuery()
				->fetchOne();

			$quota = $this->quotaRepo->findByUserAndForum($userId, $forumId);
			$old   = $quota?->usedBytes ?? 0;

			$this->quotaRepo->reconcile($userId, $forumId, $actual);
			$events[] = new QuotaReconciledEvent($userId, 0, $forumId, $old, $actual);
		}

		return new DomainEventCollection($events);
	}
}

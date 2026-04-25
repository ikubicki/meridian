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

namespace phpbb\storage\Event;

use phpbb\common\Event\DomainEvent;

/**
 * Raised when a quota counter is reconciled by the cron job.
 */
final readonly class QuotaReconciledEvent extends DomainEvent
{
	public function __construct(
		int $entityId,
		int $actorId = 0,
		public readonly int $forumId = 0,
		public readonly int $oldBytes = 0,
		public readonly int $newBytes = 0,
	) {
		parent::__construct($entityId, $actorId);
	}
}

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
 * Raised when a user's quota is exceeded during upload.
 */
final readonly class QuotaExceededEvent extends DomainEvent
{
	public function __construct(
		int $entityId,
		int $actorId,
		public readonly int $forumId,
		public readonly int $requestedBytes,
		public readonly int $maxBytes,
	) {
		parent::__construct($entityId, $actorId);
	}
}

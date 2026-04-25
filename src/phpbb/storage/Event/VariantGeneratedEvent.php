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
 * Raised when an image variant (e.g. thumbnail) is generated.
 */
final readonly class VariantGeneratedEvent extends DomainEvent
{
	public function __construct(
		string $entityId,
		int $actorId = 0,
		public readonly string $parentId = '',
	) {
		parent::__construct($entityId, $actorId);
	}
}

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
use phpbb\storage\Enum\AssetType;

/**
 * Raised when a file is successfully stored (is_orphan=1, not yet claimed).
 */
final readonly class FileStoredEvent extends DomainEvent
{
	public function __construct(
		string $entityId,
		int $actorId,
		public readonly string $fileId,
		public readonly AssetType $assetType,
	) {
		parent::__construct($entityId, $actorId);
	}
}

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

namespace phpbb\notifications\Entity;

/**
 * Notification Entity
 *
 * @TAG domain_entity
 */
final readonly class Notification
{
	public function __construct(
		public int $notificationId,
		public int $notificationTypeId,
		public string $typeName,
		public int $itemId,
		public int $itemParentId,
		public int $userId,
		public bool $read,
		public int $notificationTime,
		public array $data,
	) {
	}

	public static function fromRow(array $row): self
	{
		$raw  = $row['notification_data'] ?? '';
		$data = $raw === '' ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

		return new self(
			notificationId:      (int) $row['notification_id'],
			notificationTypeId:  (int) $row['notification_type_id'],
			typeName:            (string) ($row['notification_type_name'] ?? ''),
			itemId:              (int) $row['item_id'],
			itemParentId:        (int) $row['item_parent_id'],
			userId:              (int) $row['user_id'],
			read:                (bool) $row['notification_read'],
			notificationTime:    (int) $row['notification_time'],
			data:                $data,
		);
	}
}

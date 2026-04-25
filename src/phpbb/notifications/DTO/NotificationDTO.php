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

namespace phpbb\notifications\DTO;

use phpbb\notifications\Entity\Notification;

/**
 * Notification DTO (API Response)
 *
 * @TAG domain_dto
 */
final readonly class NotificationDTO
{
	public function __construct(
		public int $id,
		public string $type,
		public bool $unread,
		public int $createdAt,
		public array $data,
	) {
	}

	public static function fromEntity(Notification $notification): self
	{
		return new self(
			id:        $notification->notificationId,
			type:      $notification->typeName,
			unread:    !$notification->read,
			createdAt: $notification->notificationTime,
			data:      [
				'itemId'         => $notification->itemId,
				'itemParentId'   => $notification->itemParentId,
				'responders'     => $notification->data['responders'] ?? [],
				'responderCount' => count($notification->data['responders'] ?? []),
			],
		);
	}

	public function toArray(): array
	{
		return [
			'id'        => $this->id,
			'type'      => $this->type,
			'unread'    => $this->unread,
			'createdAt' => $this->createdAt,
			'data'      => $this->data,
		];
	}
}

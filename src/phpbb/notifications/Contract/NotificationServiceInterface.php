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

namespace phpbb\notifications\Contract;

use phpbb\api\DTO\PaginationContext;
use phpbb\common\Event\DomainEventCollection;
use phpbb\user\DTO\PaginatedResult;

/**
 * Notification Service Interface
 *
 * @TAG service_interface
 */
interface NotificationServiceInterface
{
	public function getUnreadCount(int $userId): int;

	public function getLastModified(int $userId): ?int;

	public function getNotifications(int $userId, PaginationContext $ctx): PaginatedResult;

	public function markRead(int $notificationId, int $userId): DomainEventCollection;

	public function markAllRead(int $userId): DomainEventCollection;
}

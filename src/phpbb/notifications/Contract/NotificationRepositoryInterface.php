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
use phpbb\db\Exception\RepositoryException;
use phpbb\notifications\Entity\Notification;
use phpbb\user\DTO\PaginatedResult;

/**
 * Notification Repository Interface
 *
 * @TAG repository_interface
 */
interface NotificationRepositoryInterface
{
	/**
	 * Find a notification by ID, scoped to the given user.
	 *
	 * @throws RepositoryException
	 */
	public function findById(int $notificationId, int $userId): ?Notification;

	/**
	 * Count unread notifications for a user.
	 *
	 * @throws RepositoryException
	 */
	public function countUnread(int $userId): int;

	/**
	 * Get the last modification timestamp for a user's notifications.
	 *
	 * @throws RepositoryException
	 */
	public function getLastModified(int $userId): ?int;

	/**
	 * List notifications for a user with pagination.
	 *
	 * @return PaginatedResult<\phpbb\notifications\DTO\NotificationDTO>
	 * @throws RepositoryException
	 */
	public function listByUser(int $userId, PaginationContext $ctx): PaginatedResult;

	/**
	 * Mark a notification as read, scoped to the given user.
	 *
	 * @throws RepositoryException
	 */
	public function markRead(int $notificationId, int $userId): bool;

	/**
	 * Mark all unread notifications as read for a user.
	 *
	 * @return int Number of rows updated
	 * @throws RepositoryException
	 */
	public function markAllRead(int $userId): int;
}

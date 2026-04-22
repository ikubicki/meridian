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

namespace phpbb\hierarchy\Contract;

interface TrackingServiceInterface
{
	/**
	 * Mark all topics in a forum as read for a user.
	 *
	 */
	public function markForumRead(int $forumId, int $userId): void;

	/**
	 * Check if a forum has unread content for a user.
	 *
	 */
	public function hasUnread(int $forumId, int $userId): bool;
}

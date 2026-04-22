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

interface SubscriptionServiceInterface
{
	/**
	 * Subscribe a user to a forum (receive notifications for new topics).
	 *
	 */
	public function subscribe(int $forumId, int $userId): void;

	/**
	 * Unsubscribe a user from a forum.
	 *
	 */
	public function unsubscribe(int $forumId, int $userId): void;

	/**
	 * Check if a user is subscribed to a forum.
	 *
	 */
	public function isSubscribed(int $forumId, int $userId): bool;
}

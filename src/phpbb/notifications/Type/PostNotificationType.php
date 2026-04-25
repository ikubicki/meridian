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

namespace phpbb\notifications\Type;

use phpbb\notifications\Contract\NotificationTypeInterface;
use phpbb\notifications\Event\RegisterNotificationTypesEvent;

final class PostNotificationType implements NotificationTypeInterface
{
	public function getTypeName(): string
	{
		return 'notification.type.post';
	}

	public function register(RegisterNotificationTypesEvent $event): void
	{
		$event->addType($this);
	}
}

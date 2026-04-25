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

namespace phpbb\notifications\Method;

use phpbb\notifications\Contract\NotificationMethodInterface;
use phpbb\notifications\Event\RegisterDeliveryMethodsEvent;

final class EmailNotificationMethod implements NotificationMethodInterface
{
	public function getMethodName(): string
	{
		return 'email';
	}

	public function register(RegisterDeliveryMethodsEvent $event): void
	{
		// Email delivery is out of scope for M8 — no-op stub
	}
}

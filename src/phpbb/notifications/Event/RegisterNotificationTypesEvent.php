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

namespace phpbb\notifications\Event;

use phpbb\notifications\Contract\NotificationTypeInterface;

/**
 * Event used to collect available notification type registrations
 */
final class RegisterNotificationTypesEvent
{
	private array $types = [];

	public function addType(NotificationTypeInterface $type): void
	{
		$this->types[] = $type;
	}

	public function getTypes(): array
	{
		return $this->types;
	}
}

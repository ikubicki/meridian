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

namespace phpbb\notifications;

use phpbb\notifications\Contract\NotificationTypeInterface;
use phpbb\notifications\Event\RegisterNotificationTypesEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class TypeRegistry
{
	private ?array $types = null;

	public function __construct(private readonly EventDispatcherInterface $dispatcher)
	{
	}

	private function initialize(): void
	{
		if ($this->types !== null) {
			return;
		}

		$event = new RegisterNotificationTypesEvent();
		$this->dispatcher->dispatch($event);

		$this->types = [];
		foreach ($event->getTypes() as $type) {
			$this->types[$type->getTypeName()] = $type;
		}
	}

	public function getByName(string $typeName): NotificationTypeInterface
	{
		$this->initialize();

		if (!isset($this->types[$typeName])) {
			throw new \InvalidArgumentException("Unknown notification type: $typeName");
		}

		return $this->types[$typeName];
	}

	public function all(): array
	{
		$this->initialize();

		return $this->types;
	}
}

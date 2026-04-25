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

use phpbb\notifications\Contract\NotificationMethodInterface;
use phpbb\notifications\Event\RegisterDeliveryMethodsEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class MethodManager
{
	private ?array $methods = null;

	public function __construct(private readonly EventDispatcherInterface $dispatcher)
	{
	}

	private function initialize(): void
	{
		if ($this->methods !== null) {
			return;
		}

		$event = new RegisterDeliveryMethodsEvent();
		$this->dispatcher->dispatch($event);

		$this->methods = [];
		foreach ($event->getMethods() as $method) {
			$this->methods[$method->getMethodName()] = $method;
		}
	}

	public function getByName(string $methodName): NotificationMethodInterface
	{
		$this->initialize();

		if (!isset($this->methods[$methodName])) {
			throw new \InvalidArgumentException("Unknown notification method: $methodName");
		}

		return $this->methods[$methodName];
	}

	public function all(): array
	{
		$this->initialize();

		return $this->methods;
	}
}

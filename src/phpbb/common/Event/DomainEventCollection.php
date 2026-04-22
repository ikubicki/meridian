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

namespace phpbb\common\Event;

final class DomainEventCollection implements \IteratorAggregate
{
	public function __construct(private readonly array $events)
	{
	}

	public function dispatch(\Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher): void
	{
		foreach ($this->events as $event) {
			$dispatcher->dispatch($event);
		}
	}

	public function getIterator(): \ArrayIterator
	{
		return new \ArrayIterator($this->events);
	}

	public function all(): array
	{
		return $this->events;
	}

	public function first(): ?DomainEvent
	{
		return $this->events[0] ?? null;
	}
}

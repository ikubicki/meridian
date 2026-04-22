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

namespace phpbb\Tests\common\Event;

use phpbb\common\Event\DomainEvent;
use phpbb\common\Event\DomainEventCollection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class DomainEventCollectionTest extends TestCase
{
	private function makeEvent(int $entityId = 1, int $actorId = 2): DomainEvent
	{
		return new readonly class ($entityId, $actorId) extends DomainEvent {};
	}

	#[Test]
	public function testDispatch_callsDispatcherForEachEvent(): void
	{
		// Arrange
		$event1 = $this->makeEvent(1, 2);
		$event2 = $this->makeEvent(3, 4);
		$collection = new DomainEventCollection([$event1, $event2]);

		$dispatcher = $this->createMock(EventDispatcherInterface::class);
		$dispatcher->expects($this->exactly(2))->method('dispatch');

		// Act
		$collection->dispatch($dispatcher);
	}

	#[Test]
	public function testFirst_returnsFirstEvent(): void
	{
		// Arrange
		$event1 = $this->makeEvent(1, 2);
		$event2 = $this->makeEvent(3, 4);
		$collection = new DomainEventCollection([$event1, $event2]);

		// Act & Assert
		$this->assertSame($event1, $collection->first());
	}

	#[Test]
	public function testFirst_emptyCollection_returnsNull(): void
	{
		// Arrange
		$collection = new DomainEventCollection([]);

		// Act & Assert
		$this->assertNull($collection->first());
	}

	#[Test]
	public function testAll_returnsAllEvents(): void
	{
		// Arrange
		$event1 = $this->makeEvent(1, 2);
		$event2 = $this->makeEvent(3, 4);
		$events = [$event1, $event2];
		$collection = new DomainEventCollection($events);

		// Act & Assert
		$this->assertSame($events, $collection->all());
	}

	#[Test]
	public function testGetIterator_isIterable(): void
	{
		// Arrange
		$event1 = $this->makeEvent(1, 2);
		$event2 = $this->makeEvent(3, 4);
		$collection = new DomainEventCollection([$event1, $event2]);

		// Act
		$iterated = [];
		foreach ($collection as $event) {
			$iterated[] = $event;
		}

		// Assert
		$this->assertCount(2, $iterated);
		$this->assertSame($event1, $iterated[0]);
		$this->assertSame($event2, $iterated[1]);
	}
}

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

namespace phpbb\Tests\notifications\TypeRegistry;

use phpbb\notifications\Event\RegisterNotificationTypesEvent;
use phpbb\notifications\Type\PostNotificationType;
use phpbb\notifications\TypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class TypeRegistryTest extends TestCase
{
	private EventDispatcherInterface&MockObject $dispatcher;
	private TypeRegistry $registry;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dispatcher = $this->createMock(EventDispatcherInterface::class);
		$this->registry   = new TypeRegistry($this->dispatcher);
	}

	#[Test]
	public function getByNameDispatchesEventLazily(): void
	{
		// Arrange
		$this->dispatcher->expects($this->once())
			->method('dispatch')
			->willReturnCallback(function (object $event): object {
				if ($event instanceof RegisterNotificationTypesEvent) {
					$event->addType(new PostNotificationType());
				}

				return $event;
			});

		// Act
		$this->registry->all();

		// Assert — $this->once() above covers the assertion
	}

	#[Test]
	public function getByNameDeduplicatesDispatch(): void
	{
		// Arrange
		$this->dispatcher->expects($this->once())
			->method('dispatch')
			->willReturnCallback(function (object $event): object {
				if ($event instanceof RegisterNotificationTypesEvent) {
					$event->addType(new PostNotificationType());
				}

				return $event;
			});

		// Act — call twice
		$this->registry->all();
		$this->registry->all();

		// Assert — $this->once() above covers the assertion
	}

	#[Test]
	public function getByNameThrowsForUnknownType(): void
	{
		// Arrange
		$this->dispatcher->expects($this->once())
			->method('dispatch')
			->willReturnCallback(fn (object $event): object => $event);

		// Assert
		$this->expectException(\InvalidArgumentException::class);

		// Act
		$this->registry->getByName('nonexistent');
	}

	#[Test]
	public function allReturnsRegisteredMap(): void
	{
		// Arrange
		$type = new PostNotificationType();
		$this->dispatcher->expects($this->once())
			->method('dispatch')
			->willReturnCallback(function (object $event) use ($type): object {
				if ($event instanceof RegisterNotificationTypesEvent) {
					$event->addType($type);
				}

				return $event;
			});

		// Act
		$map = $this->registry->all();

		// Assert
		$this->assertArrayHasKey('notification.type.post', $map);
		$this->assertSame($type, $map['notification.type.post']);
	}
}

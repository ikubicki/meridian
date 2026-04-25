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

namespace phpbb\Tests\notifications\MethodManager;

use phpbb\notifications\Event\RegisterDeliveryMethodsEvent;
use phpbb\notifications\Method\BoardNotificationMethod;
use phpbb\notifications\MethodManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class MethodManagerTest extends TestCase
{
	private EventDispatcherInterface&MockObject $dispatcher;
	private MethodManager $manager;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dispatcher = $this->createMock(EventDispatcherInterface::class);
		$this->manager    = new MethodManager($this->dispatcher);
	}

	#[Test]
	public function getByNameDispatchesEventLazily(): void
	{
		// Arrange
		$this->dispatcher->expects($this->once())
			->method('dispatch')
			->willReturnCallback(function (object $event): object {
				if ($event instanceof RegisterDeliveryMethodsEvent) {
					$event->addMethod(new BoardNotificationMethod());
				}

				return $event;
			});

		// Act — call twice, dispatch must happen only once
		$this->manager->all();
		$this->manager->all();

		// Assert — $this->once() above covers the assertion
	}

	#[Test]
	public function getByNameThrowsForUnknownMethod(): void
	{
		// Arrange
		$this->dispatcher->expects($this->once())
			->method('dispatch')
			->willReturnCallback(fn (object $event): object => $event);

		// Assert
		$this->expectException(\InvalidArgumentException::class);

		// Act
		$this->manager->getByName('nonexistent');
	}

	#[Test]
	public function allReturnsRegisteredMap(): void
	{
		// Arrange
		$method = new BoardNotificationMethod();
		$this->dispatcher->expects($this->once())
			->method('dispatch')
			->willReturnCallback(function (object $event) use ($method): object {
				if ($event instanceof RegisterDeliveryMethodsEvent) {
					$event->addMethod($method);
				}

				return $event;
			});

		// Act
		$map = $this->manager->all();

		// Assert
		$this->assertArrayHasKey('board', $map);
		$this->assertSame($method, $map['board']);
	}
}

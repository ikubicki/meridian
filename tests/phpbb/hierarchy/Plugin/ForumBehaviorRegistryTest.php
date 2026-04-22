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

namespace phpbb\Tests\hierarchy\Plugin;

use phpbb\hierarchy\DTO\CreateForumRequest;
use phpbb\hierarchy\DTO\UpdateForumRequest;
use phpbb\hierarchy\Entity\Forum;
use phpbb\hierarchy\Plugin\ForumBehaviorInterface;
use phpbb\hierarchy\Plugin\ForumBehaviorRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ForumBehaviorRegistryTest extends TestCase
{
	private function makeBehavior(bool $supports): ForumBehaviorInterface
	{
		return new class ($supports) implements ForumBehaviorInterface {
			public function __construct(private readonly bool $supports)
			{
			}

			public function supports(string $forumType): bool
			{
				return $this->supports;
			}

			public function decorateCreate(CreateForumRequest $request): CreateForumRequest
			{
				return $request;
			}

			public function decorateUpdate(UpdateForumRequest $request): UpdateForumRequest
			{
				return $request;
			}

			public function decorateResponse(Forum $forum): Forum
			{
				return $forum;
			}
		};
	}

	#[Test]
	public function testRegister_addsBehavior_countIncrements(): void
	{
		$registry = new ForumBehaviorRegistry();
		$registry->register($this->makeBehavior(true));
		$registry->register($this->makeBehavior(false));

		$this->assertSame(2, $registry->count());
	}

	#[Test]
	public function testGetBehaviors_returnsAllRegistered(): void
	{
		$registry = new ForumBehaviorRegistry();
		$registry->register($this->makeBehavior(true));
		$registry->register($this->makeBehavior(false));

		$this->assertCount(2, $registry->getBehaviors());
	}

	#[Test]
	public function testGetForType_returnsBehaviorsThatSupportType(): void
	{
		$registry = new ForumBehaviorRegistry();
		$registry->register($this->makeBehavior(true));
		$registry->register($this->makeBehavior(false));

		$result = $registry->getForType('forum');

		$this->assertCount(1, $result);
	}

	#[Test]
	public function testGetForType_emptyRegistry_returnsEmptyArray(): void
	{
		$registry = new ForumBehaviorRegistry();

		$this->assertSame([], $registry->getForType('link'));
	}

	#[Test]
	public function testGetForType_returnsReindexedArray(): void
	{
		$registry = new ForumBehaviorRegistry();
		$registry->register($this->makeBehavior(false));
		$registry->register($this->makeBehavior(true));

		$result = $registry->getForType('forum');

		$this->assertArrayHasKey(0, $result);
		$this->assertCount(1, $result);
	}
}

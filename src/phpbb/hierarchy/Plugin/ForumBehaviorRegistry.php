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

namespace phpbb\hierarchy\Plugin;

final class ForumBehaviorRegistry
{
	/** @var ForumBehaviorInterface[] */
	private array $behaviors = [];

	public function register(ForumBehaviorInterface $behavior): void
	{
		$this->behaviors[] = $behavior;
	}

	/**
	 * @return ForumBehaviorInterface[]
	 */
	public function getBehaviors(): array
	{
		return $this->behaviors;
	}

	/**
	 * @return ForumBehaviorInterface[]
	 */
	public function getForType(string $forumType): array
	{
		return array_values(
			array_filter(
				$this->behaviors,
				static fn (ForumBehaviorInterface $b): bool => $b->supports($forumType),
			)
		);
	}

	public function count(): int
	{
		return count($this->behaviors);
	}
}

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

namespace phpbb\cache;

use phpbb\cache\backend\CacheBackendInterface;
use phpbb\cache\marshaller\MarshallerInterface;

/**
 * Cache pool factory.
 *
 * Creates a CachePool per namespace, each sharing the same backend and marshaller.
 * Pool creation is lightweight (no I/O) and pools are not memoized, so callers
 * should store returned pools themselves if they need repeated access.
 */
class CachePoolFactory implements CachePoolFactoryInterface
{
	public function __construct(
		private readonly CacheBackendInterface $backend,
		private readonly MarshallerInterface $marshaller,
	) {
	}

	public function getPool(string $namespace): TagAwareCacheInterface
	{
		$tagVersionStore = new TagVersionStore($this->backend, $this->marshaller);

		return new CachePool($namespace, $this->backend, $this->marshaller, $tagVersionStore);
	}
}

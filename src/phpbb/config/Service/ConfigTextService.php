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

namespace phpbb\config\Service;

use phpbb\config\Contract\ConfigTextRepositoryInterface;
use phpbb\config\Contract\ConfigTextServiceInterface;

final class ConfigTextService implements ConfigTextServiceInterface
{
	public function __construct(
		private readonly ConfigTextRepositoryInterface $repository,
	) {
	}

	public function get(string $key): ?string
	{
		return $this->repository->get($key);
	}

	public function set(string $key, string $value): void
	{
		$this->repository->set($key, $value);
	}

	public function delete(string $key): int
	{
		return $this->repository->delete($key);
	}
}

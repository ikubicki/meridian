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

namespace phpbb\config\Contract;

interface ConfigTextRepositoryInterface
{
	public function get(string $key): ?string;

	public function set(string $key, string $value): void;

	public function delete(string $key): int;
}

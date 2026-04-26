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

interface ConfigServiceInterface
{
	public function get(string $key, string $default = ''): string;

	public function getInt(string $key, int $default = 0): int;

	public function getBool(string $key, bool $default = false): bool;

	public function getAll(): array;

	public function set(string $key, string $value, bool $isDynamic = false): void;

	public function increment(string $key, int $by = 1): void;

	public function delete(string $key): int;
}

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

namespace phpbb\db;

/**
 * Static factory for the shared PDO connection.
 *
 * Registered in services.yaml and lazily instantiated by the container.
 * Only one PDO instance is created per request.
 */
final class PdoFactory
{
	public static function create(
		string $host,
		string $dbname,
		string $user,
		string $password,
		int $port = 3306,
	): \PDO {
		$dsn = sprintf(
			'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
			$host,
			$port,
			$dbname,
		);

		return new \PDO($dsn, $user, $password, [
			\PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
			\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
			\PDO::ATTR_EMULATE_PREPARES   => false,
		]);
	}
}

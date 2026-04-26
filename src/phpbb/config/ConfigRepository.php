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

namespace phpbb\config;

use phpbb\config\Contract\ConfigRepositoryInterface;
use phpbb\db\Exception\RepositoryException;

final class ConfigRepository implements ConfigRepositoryInterface
{
	public function __construct(
		private readonly \Doctrine\DBAL\Connection $connection,
	) {
	}

	public function get(string $key, string $default = ''): string
	{
		try {
			$sql = 'SELECT config_value FROM phpbb_config WHERE config_name = :key LIMIT 1';
			$row = $this->connection->fetchAssociative($sql, ['key' => $key]);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException($e->getMessage(), (int) $e->getCode(), $e);
		}

		if ($row === false) {
			return $default;
		}

		return (string) $row['config_value'];
	}
}

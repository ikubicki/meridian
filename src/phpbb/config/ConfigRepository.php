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

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
			$qb  = $this->connection->createQueryBuilder();
			$row = $qb->select('config_value')
				->from('phpbb_config')
				->where($qb->expr()->eq('config_name', ':key'))
				->setMaxResults(1)
				->setParameter('key', $key)
				->executeQuery()
				->fetchAssociative();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException($e->getMessage(), (int) $e->getCode(), $e);
		}

		if ($row === false) {
			return $default;
		}

		return (string) $row['config_value'];
	}

	public function set(string $key, string $value, bool $isDynamic = false): void
	{
		try {
			$qb      = $this->connection->createQueryBuilder();
			$affected = (int) $qb->update('phpbb_config')
				->set('config_value', ':value')
				->set('is_dynamic', ':isDynamic')
				->where($qb->expr()->eq('config_name', ':key'))
				->setParameter('value', $value)
				->setParameter('isDynamic', (int) $isDynamic)
				->setParameter('key', $key)
				->executeStatement();

			if ($affected === 0) {
				$qb = $this->connection->createQueryBuilder();
				$qb->insert('phpbb_config')
					->values([
						'config_name'  => ':key',
						'config_value' => ':value',
						'is_dynamic'   => ':isDynamic',
					])
					->setParameter('key', $key)
					->setParameter('value', $value)
					->setParameter('isDynamic', (int) $isDynamic)
					->executeStatement();
			}
		} catch (UniqueConstraintViolationException $e) {
			// Race condition: another process inserted between our update (0 rows) and insert.
			// Retry as update, which is now guaranteed to find the row.
			$qb = $this->connection->createQueryBuilder();
			$qb->update('phpbb_config')
				->set('config_value', ':value')
				->set('is_dynamic', ':isDynamic')
				->where($qb->expr()->eq('config_name', ':key'))
				->setParameter('value', $value)
				->setParameter('isDynamic', (int) $isDynamic)
				->setParameter('key', $key)
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException($e->getMessage(), (int) $e->getCode(), $e);
		}
	}

	public function getAll(bool $dynamicOnly = false): array
	{
		try {
			$qb = $this->connection->createQueryBuilder()
				->select('config_name', 'config_value')
				->from('phpbb_config');

			if ($dynamicOnly) {
				$qb->where('is_dynamic = 1');
			}

			$rows = $qb->executeQuery()->fetchAllAssociative();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException($e->getMessage(), (int) $e->getCode(), $e);
		}

		return array_column($rows, 'config_value', 'config_name');
	}

	public function increment(string $key, int $by = 1): void
	{
		try {
			$qb      = $this->connection->createQueryBuilder();
			$affected = (int) $qb->update('phpbb_config')
				->set('config_value', 'config_value + :by')
				->where($qb->expr()->eq('config_name', ':key'))
				->setParameter('by', $by)
				->setParameter('key', $key)
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException($e->getMessage(), (int) $e->getCode(), $e);
		}

		if ($affected === 0) {
			throw new RepositoryException("Config key '{$key}' not found");
		}
	}

	public function delete(string $key): int
	{
		try {
			$qb = $this->connection->createQueryBuilder();

			return (int) $qb->delete('phpbb_config')
				->where($qb->expr()->eq('config_name', ':key'))
				->setParameter('key', $key)
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException($e->getMessage(), (int) $e->getCode(), $e);
		}
	}
}

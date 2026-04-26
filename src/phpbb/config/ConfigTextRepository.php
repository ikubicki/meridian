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

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use phpbb\config\Contract\ConfigTextRepositoryInterface;
use phpbb\db\Exception\RepositoryException;

final class ConfigTextRepository implements ConfigTextRepositoryInterface
{
	public function __construct(
		private readonly Connection $connection,
	) {
	}

	public function get(string $key): ?string
	{
		try {
			$qb  = $this->connection->createQueryBuilder();
			$row = $qb->select('config_text')
				->from('phpbb_config_text')
				->where($qb->expr()->eq('config_name', ':key'))
				->setMaxResults(1)
				->setParameter('key', $key)
				->executeQuery()
				->fetchAssociative();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException($e->getMessage(), (int) $e->getCode(), $e);
		}

		if ($row === false) {
			return null;
		}

		return (string) $row['config_text'];
	}

	public function set(string $key, string $value): void
	{
		try {
			$qb      = $this->connection->createQueryBuilder();
			$affected = (int) $qb->update('phpbb_config_text')
				->set('config_text', ':value')
				->where($qb->expr()->eq('config_name', ':key'))
				->setParameter('value', $value)
				->setParameter('key', $key)
				->executeStatement();

			if ($affected === 0) {
				$qb = $this->connection->createQueryBuilder();
				$qb->insert('phpbb_config_text')
					->values([
						'config_name' => ':key',
						'config_text' => ':value',
					])
					->setParameter('key', $key)
					->setParameter('value', $value)
					->executeStatement();
			}
		} catch (UniqueConstraintViolationException) {
			// Race condition: retry as update
			$qb = $this->connection->createQueryBuilder();
			$qb->update('phpbb_config_text')
				->set('config_text', ':value')
				->where($qb->expr()->eq('config_name', ':key'))
				->setParameter('value', $value)
				->setParameter('key', $key)
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException($e->getMessage(), (int) $e->getCode(), $e);
		}
	}

	public function delete(string $key): int
	{
		try {
			$qb = $this->connection->createQueryBuilder();

			return (int) $qb->delete('phpbb_config_text')
				->where($qb->expr()->eq('config_name', ':key'))
				->setParameter('key', $key)
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException($e->getMessage(), (int) $e->getCode(), $e);
		}
	}
}

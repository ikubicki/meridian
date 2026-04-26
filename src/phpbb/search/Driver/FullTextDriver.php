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

namespace phpbb\search\Driver;

use phpbb\api\DTO\PaginationContext;
use phpbb\db\Exception\RepositoryException;
use phpbb\search\Contract\SearchDriverInterface;
use phpbb\search\DTO\SearchQuery;
use phpbb\search\DTO\SearchResultDTO;
use phpbb\user\DTO\PaginatedResult;

final class FullTextDriver implements SearchDriverInterface
{
	public function __construct(
		private readonly \Doctrine\DBAL\Connection $connection,
		private readonly LikeDriver $fallback,
	) {
	}

	public function search(SearchQuery $query, PaginationContext $ctx): PaginatedResult
	{
		$platform = $this->connection->getDatabasePlatform();

		if ($platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform) {
			return $this->searchMySQL($query, $ctx);
		}

		if ($platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
			return $this->searchPostgreSQL($query, $ctx);
		}

		return $this->fallback->search($query, $ctx);
	}

	private function searchMySQL(SearchQuery $query, PaginationContext $ctx): PaginatedResult
	{
		try {
			$likeTerm = '%' . addcslashes($query->keywords, '%_') . '%';
			$offset   = ($ctx->page - 1) * $ctx->perPage;

			if ($query->searchIn === 'titles') {
				$condition = '(p.post_subject LIKE :qLike OR t.topic_title LIKE :qLike)';
				$base      = $this->connection->createQueryBuilder()
					->from('phpbb_posts', 'p')
					->leftJoin('p', 'phpbb_topics', 't', 't.topic_id = p.topic_id')
					->leftJoin('p', 'phpbb_forums', 'f', 'f.forum_id = p.forum_id')
					->where($condition)
					->andWhere('p.post_visibility = 1')
					->setParameter('qLike', $likeTerm);
			} else {
				$base = $this->connection->createQueryBuilder()
					->from('phpbb_posts', 'p')
					->leftJoin('p', 'phpbb_topics', 't', 't.topic_id = p.topic_id')
					->leftJoin('p', 'phpbb_forums', 'f', 'f.forum_id = p.forum_id')
					->where('(MATCH(p.post_text, p.post_subject) AGAINST(:q IN BOOLEAN MODE) OR t.topic_title LIKE :qLike OR f.forum_name LIKE :qLike OR f.forum_desc LIKE :qLike)')
					->andWhere('p.post_visibility = 1')
					->setParameter('q', $query->keywords)
					->setParameter('qLike', $likeTerm);
			}

			if ($query->forumId !== null) {
				$base->andWhere('p.forum_id = :forumId')->setParameter('forumId', $query->forumId);
			}

			if ($query->topicId !== null) {
				$base->andWhere('p.topic_id = :topicId')->setParameter('topicId', $query->topicId);
			}

			if ($query->userId !== null) {
				$base->andWhere('p.poster_id = :userId')->setParameter('userId', $query->userId);
			}

			if ($query->dateFrom !== null) {
				$base->andWhere('p.post_time >= :dateFrom')->setParameter('dateFrom', $query->dateFrom);
			}

			if ($query->dateTo !== null) {
				$base->andWhere('p.post_time <= :dateTo')->setParameter('dateTo', $query->dateTo);
			}

			$total = (int) (clone $base)->select('COUNT(*)')->executeQuery()->fetchOne();

			$rows = (clone $base)
				->select('p.post_id, p.topic_id, p.forum_id, p.post_subject, p.post_text, p.post_time, t.topic_title, f.forum_name')
				->orderBy('p.post_time', $query->sortBy === 'date_asc' ? 'ASC' : 'DESC')
				->setMaxResults($ctx->perPage)
				->setFirstResult($offset)
				->executeQuery()
				->fetchAllAssociative();

			$items = array_map(fn (array $row) => SearchResultDTO::fromRow($row), $rows);

			return new PaginatedResult(items: $items, total: $total, page: $ctx->page, perPage: $ctx->perPage);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Full-text search failed', previous: $e);
		}
	}

	private function searchPostgreSQL(SearchQuery $query, PaginationContext $ctx): PaginatedResult
	{
		try {
			$likeTerm = '%' . addcslashes($query->keywords, '%_') . '%';
			$offset   = ($ctx->page - 1) * $ctx->perPage;

			$base = $this->connection->createQueryBuilder()
				->from('phpbb_posts', 'p')
				->leftJoin('p', 'phpbb_topics', 't', 't.topic_id = p.topic_id')
				->leftJoin('p', 'phpbb_forums', 'f', 'f.forum_id = p.forum_id')
				->where("(to_tsvector('english', p.post_text || ' ' || p.post_subject) @@ plainto_tsquery('english', :q) OR t.topic_title LIKE :qLike OR f.forum_name LIKE :qLike OR f.forum_desc LIKE :qLike)")
				->andWhere('p.post_visibility = 1')
				->setParameter('q', $query->keywords)
				->setParameter('qLike', $likeTerm);

			if ($query->forumId !== null) {
				$base->andWhere('p.forum_id = :forumId')->setParameter('forumId', $query->forumId);
			}

			if ($query->topicId !== null) {
				$base->andWhere('p.topic_id = :topicId')->setParameter('topicId', $query->topicId);
			}

			if ($query->userId !== null) {
				$base->andWhere('p.poster_id = :userId')->setParameter('userId', $query->userId);
			}

			if ($query->dateFrom !== null) {
				$base->andWhere('p.post_time >= :dateFrom')->setParameter('dateFrom', $query->dateFrom);
			}

			if ($query->dateTo !== null) {
				$base->andWhere('p.post_time <= :dateTo')->setParameter('dateTo', $query->dateTo);
			}

			$total = (int) (clone $base)->select('COUNT(*)')->executeQuery()->fetchOne();

			$rows = (clone $base)
				->select('p.post_id, p.topic_id, p.forum_id, p.post_subject, p.post_text, p.post_time, t.topic_title, f.forum_name')
				->orderBy('p.post_time', $query->sortBy === 'date_asc' ? 'ASC' : 'DESC')
				->setMaxResults($ctx->perPage)
				->setFirstResult($offset)
				->executeQuery()
				->fetchAllAssociative();

			$items = array_map(fn (array $row) => SearchResultDTO::fromRow($row), $rows);

			return new PaginatedResult(items: $items, total: $total, page: $ctx->page, perPage: $ctx->perPage);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Full-text search (PG) failed', previous: $e);
		}
	}
}

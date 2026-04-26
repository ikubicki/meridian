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

class LikeDriver implements SearchDriverInterface
{
	public function __construct(
		private readonly \Doctrine\DBAL\Connection $connection,
	) {
	}

	public function search(SearchQuery $query, PaginationContext $ctx): PaginatedResult
	{
		try {
			$searchTerm = '%' . addcslashes($query->keywords, '%_') . '%';
			$offset     = ($ctx->page - 1) * $ctx->perPage;

			$base = $this->connection->createQueryBuilder()
				->from('phpbb_posts', 'p')
				->leftJoin('p', 'phpbb_topics', 't', 't.topic_id = p.topic_id')
				->leftJoin('p', 'phpbb_forums', 'f', 'f.forum_id = p.forum_id')
				->where($this->buildSearchCondition($query))
				->andWhere('p.post_visibility = 1')
				->setParameter('q', $searchTerm);

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
				->orderBy('p.post_time', $this->buildOrderBy($query))
				->setMaxResults($ctx->perPage)
				->setFirstResult($offset)
				->executeQuery()
				->fetchAllAssociative();

			$items = array_map(
				fn (array $row) => SearchResultDTO::fromRow($row),
				$rows,
			);

			return new PaginatedResult(
				items:   $items,
				total:   $total,
				page:    $ctx->page,
				perPage: $ctx->perPage,
			);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Search failed', previous: $e);
		}
	}

	private function buildSearchCondition(SearchQuery $query): string
	{
		return match ($query->searchIn) {
			'posts'  => '(p.post_text LIKE :q)',
			'titles' => '(p.post_subject LIKE :q OR t.topic_title LIKE :q)',
			default  => '(p.post_text LIKE :q OR p.post_subject LIKE :q OR t.topic_title LIKE :q OR f.forum_name LIKE :q OR f.forum_desc LIKE :q)',
		};
	}

	private function buildOrderBy(SearchQuery $query): string
	{
		return match ($query->sortBy) {
			'date_asc' => 'ASC',
			default    => 'DESC',
		};
	}
}

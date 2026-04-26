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

use Doctrine\DBAL\ArrayParameterType;
use phpbb\api\DTO\PaginationContext;
use phpbb\db\Exception\RepositoryException;
use phpbb\search\Contract\SearchDriverInterface;
use phpbb\search\DTO\SearchQuery;
use phpbb\search\DTO\SearchResultDTO;
use phpbb\search\Tokenizer\NativeTokenizer;
use phpbb\user\DTO\PaginatedResult;

final class NativeDriver implements SearchDriverInterface
{
	public function __construct(
		private readonly \Doctrine\DBAL\Connection $connection,
		private readonly NativeTokenizer $tokenizer,
		private readonly LikeDriver $fallback,
	) {
	}

	public function search(SearchQuery $query, PaginationContext $ctx): PaginatedResult
	{
		$tokens = $this->tokenizer->tokenize($query->keywords);

		if (empty($tokens['must']) && empty($tokens['should'])) {
			return $this->fallback->search($query, $ctx);
		}

		$allWords = array_merge($tokens['must'], $tokens['mustNot'], $tokens['should']);
		$wordIds  = $this->fetchWordIds($allWords);

		if (empty($wordIds)) {
			return $this->fallback->search($query, $ctx);
		}

		$postIds = $this->resolveCandidatePostIds($tokens, $wordIds, $query->searchIn);

		if (empty($postIds)) {
			return new PaginatedResult(items: [], total: 0, page: $ctx->page, perPage: $ctx->perPage);
		}

		return $this->fetchPostsByIds($postIds, $query, $ctx);
	}

	/**
	 * @param string[] $words
	 * @return array<string, int>
	 */
	private function fetchWordIds(array $words): array
	{
		if (empty($words)) {
			return [];
		}

		$rows = $this->connection->createQueryBuilder()
			->select('word_id', 'word_text')
			->from('phpbb_search_wordlist')
			->where('word_text IN (:words)')
			->setParameter('words', $words, ArrayParameterType::STRING)
			->executeQuery()
			->fetchAllAssociative();

		$map = [];
		foreach ($rows as $row) {
			$map[$row['word_text']] = (int) $row['word_id'];
		}

		return $map;
	}

	/**
	 * @param array{must: string[], mustNot: string[], should: string[]} $tokens
	 * @param array<string, int> $wordIds
	 * @return int[]
	 */
	private function resolveCandidatePostIds(array $tokens, array $wordIds, string $searchIn): array
	{
		$mustPostIds = null;
		foreach ($tokens['must'] as $word) {
			$wid = $wordIds[$word] ?? null;
			if ($wid === null) {
				return [];
			}
			$ids = $this->fetchPostIdsForWord($wid, $searchIn);
			if ($mustPostIds === null) {
				$mustPostIds = array_flip($ids);
			} else {
				$mustPostIds = array_intersect_key($mustPostIds, array_flip($ids));
			}
			if (empty($mustPostIds)) {
				return [];
			}
		}

		$shouldPostIds = [];
		foreach ($tokens['should'] as $word) {
			$wid = $wordIds[$word] ?? null;
			if ($wid === null) {
				continue;
			}
			$ids = $this->fetchPostIdsForWord($wid, $searchIn);
			foreach ($ids as $id) {
				$shouldPostIds[(int) $id] = true;
			}
		}

		if ($mustPostIds === null && empty($shouldPostIds)) {
			return [];
		}

		if ($mustPostIds === null) {
			$candidates = array_keys($shouldPostIds);
		} elseif (empty($shouldPostIds)) {
			$candidates = array_keys($mustPostIds);
		} else {
			$candidates = array_keys(array_intersect_key($mustPostIds, $shouldPostIds));
		}

		foreach ($tokens['mustNot'] as $word) {
			$wid = $wordIds[$word] ?? null;
			if ($wid === null) {
				continue;
			}
			$excludeIds = $this->fetchPostIdsForWord($wid, $searchIn);
			$exclude    = array_flip($excludeIds);
			$candidates = array_values(array_filter(
				$candidates,
				fn ($id) => !isset($exclude[$id]),
			));
		}

		return array_map('intval', $candidates);
	}

	/**
	 * @return list<int>
	 */
	private function fetchPostIdsForWord(int $wordId, string $searchIn): array
	{
		$qb = $this->connection->createQueryBuilder()
			->select('post_id')
			->from('phpbb_search_wordmatch')
			->where('word_id = :wordId')
			->setParameter('wordId', $wordId);

		match ($searchIn) {
			'titles' => $qb->andWhere('title_match = 1'),
			'posts'  => $qb->andWhere('title_match = 0'),
			default  => null,
		};

		return $qb->executeQuery()->fetchFirstColumn();
	}

	/**
	 * @param int[] $postIds
	 */
	private function fetchPostsByIds(array $postIds, SearchQuery $query, PaginationContext $ctx): PaginatedResult
	{
		try {
			$qb = $this->connection->createQueryBuilder()
				->from('phpbb_posts', 'p')
				->leftJoin('p', 'phpbb_topics', 't', 't.topic_id = p.topic_id')
				->leftJoin('p', 'phpbb_forums', 'f', 'f.forum_id = p.forum_id')
				->andWhere('p.post_visibility = 1');

			foreach ($postIds as $i => $id) {
				$qb->setParameter('pid' . $i, $id);
			}

			$idParams = implode(',', array_map(fn ($i) => ':pid' . $i, array_keys($postIds)));
			$qb->andWhere("p.post_id IN ({$idParams})");

			if ($query->forumId !== null) {
				$qb->andWhere('p.forum_id = :forumId')->setParameter('forumId', $query->forumId);
			}

			if ($query->topicId !== null) {
				$qb->andWhere('p.topic_id = :topicId')->setParameter('topicId', $query->topicId);
			}

			if ($query->userId !== null) {
				$qb->andWhere('p.poster_id = :userId')->setParameter('userId', $query->userId);
			}

			if ($query->dateFrom !== null) {
				$qb->andWhere('p.post_time >= :dateFrom')->setParameter('dateFrom', $query->dateFrom);
			}

			if ($query->dateTo !== null) {
				$qb->andWhere('p.post_time <= :dateTo')->setParameter('dateTo', $query->dateTo);
			}

			$orderDir    = $query->sortBy === 'date_asc' ? 'ASC' : 'DESC';
			$actualTotal = (int) (clone $qb)->select('COUNT(*)')->executeQuery()->fetchOne();

			$rows = (clone $qb)
				->select('p.post_id, p.topic_id, p.forum_id, p.post_subject, p.post_text, p.post_time, t.topic_title, f.forum_name')
				->orderBy('p.post_time', $orderDir)
				->setMaxResults($ctx->perPage)
				->setFirstResult(($ctx->page - 1) * $ctx->perPage)
				->executeQuery()
				->fetchAllAssociative();

			$items = array_map(fn (array $row) => SearchResultDTO::fromRow($row), $rows);

			return new PaginatedResult(items: $items, total: $actualTotal, page: $ctx->page, perPage: $ctx->perPage);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Native search fetch failed', previous: $e);
		}
	}
}

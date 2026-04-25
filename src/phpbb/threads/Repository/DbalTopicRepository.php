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

namespace phpbb\threads\Repository;

use phpbb\api\DTO\PaginationContext;
use phpbb\db\Exception\RepositoryException;
use phpbb\threads\Contract\TopicRepositoryInterface;
use phpbb\threads\DTO\CreateTopicRequest;
use phpbb\threads\DTO\TopicDTO;
use phpbb\threads\Entity\Topic;
use phpbb\user\DTO\PaginatedResult;

class DbalTopicRepository implements TopicRepositoryInterface
{
	private const TABLE = 'phpbb_topics';

	public function __construct(
		private readonly \Doctrine\DBAL\Connection $connection,
	) {
	}

	public function findById(int $id): ?Topic
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$row = $qb->select(
				'topic_id',
				'forum_id',
				'topic_title',
				'topic_poster',
				'topic_time',
				'topic_posts_approved',
				'topic_last_post_time',
				'topic_last_poster_name',
				'topic_last_poster_id',
				'topic_last_poster_colour',
				'topic_first_post_id',
				'topic_last_post_id',
				'topic_visibility',
				'topic_first_poster_name',
				'topic_first_poster_colour'
			)
				->from(self::TABLE)
				->where($qb->expr()->eq('topic_id', ':id'))
				->setParameter('id', $id)
				->setMaxResults(1)
				->executeQuery()
				->fetchAssociative();

			return $row !== false ? $this->hydrate($row) : null;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find topic by ID', previous: $e);
		}
	}

	public function findByForum(int $forumId, PaginationContext $ctx): PaginatedResult
	{
		try {
			$base = $this->connection->createQueryBuilder()
				->from(self::TABLE)
				->where('forum_id = :forumId')
				->andWhere('topic_visibility = 1')
				->setParameter('forumId', $forumId);

			$total = (int) (clone $base)->select('COUNT(*)')
				->executeQuery()
				->fetchOne();

			$offset = ($ctx->page - 1) * $ctx->perPage;

			$rows = (clone $base)
				->select(
					'topic_id',
					'forum_id',
					'topic_title',
					'topic_poster',
					'topic_time',
					'topic_posts_approved',
					'topic_last_post_time',
					'topic_last_poster_name',
					'topic_last_poster_id',
					'topic_last_poster_colour',
					'topic_first_post_id',
					'topic_last_post_id',
					'topic_visibility',
					'topic_first_poster_name',
					'topic_first_poster_colour'
				)
				->orderBy('topic_last_post_time', 'DESC')
				->setMaxResults($ctx->perPage)
				->setFirstResult($offset)
				->executeQuery()
				->fetchAllAssociative();

			$items = array_map(
				fn (array $row) => TopicDTO::fromEntity($this->hydrate($row)),
				$rows,
			);

			return new PaginatedResult(
				items: $items,
				total: $total,
				page: $ctx->page,
				perPage: $ctx->perPage,
			);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find topics by forum', previous: $e);
		}
	}

	public function insert(CreateTopicRequest $request, int $now): int
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$qb->insert(self::TABLE)
				->values([
					'forum_id'                  => ':forumId',
					'topic_title'               => ':title',
					'topic_poster'              => ':posterId',
					'topic_time'                => ':now',
					'topic_first_poster_name'   => ':firstPosterName',
					'topic_first_poster_colour' => ':firstPosterColour',
					'topic_last_poster_id'      => ':posterId',
					'topic_last_poster_name'    => ':posterName',
					'topic_last_poster_colour'  => ':posterColour',
					'topic_last_post_subject'   => ':title',
					'topic_last_post_time'      => ':now',
					'topic_visibility'          => '1',
				])
				->setParameter('forumId', $request->forumId)
				->setParameter('title', $request->title)
				->setParameter('posterId', $request->actorId)
				->setParameter('now', $now)
				->setParameter('firstPosterName', $request->actorUsername)
				->setParameter('firstPosterColour', $request->actorColour)
				->setParameter('posterName', $request->actorUsername)
				->setParameter('posterColour', $request->actorColour)
				->executeStatement();

			return (int) $this->connection->lastInsertId();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to insert topic', previous: $e);
		}
	}

	public function updateFirstLastPost(int $topicId, int $postId): void
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$qb->update(self::TABLE)
				->set('topic_first_post_id', ':postId')
				->set('topic_last_post_id', ':postId')
				->where($qb->expr()->eq('topic_id', ':topicId'))
				->setParameter('postId', $postId)
				->setParameter('topicId', $topicId)
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to update first/last post IDs', previous: $e);
		}
	}

	public function updateLastPost(
		int $topicId,
		int $postId,
		int $posterId,
		string $posterName,
		string $posterColour,
		int $now,
	): void {
		try {
			$qb = $this->connection->createQueryBuilder();
			$qb->update(self::TABLE)
				->set('topic_last_post_id', ':postId')
				->set('topic_last_poster_id', ':posterId')
				->set('topic_last_poster_name', ':posterName')
				->set('topic_last_poster_colour', ':posterColour')
				->set('topic_last_post_time', ':now')
				->where($qb->expr()->eq('topic_id', ':topicId'))
				->setParameter('postId', $postId)
				->setParameter('posterId', $posterId)
				->setParameter('posterName', $posterName)
				->setParameter('posterColour', $posterColour)
				->setParameter('now', $now)
				->setParameter('topicId', $topicId)
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to update last post denormalization', previous: $e);
		}
	}

	private function hydrate(array $row): Topic
	{
		return new Topic(
			id:                 (int) $row['topic_id'],
			forumId:            (int) $row['forum_id'],
			title:              (string) $row['topic_title'],
			posterId:           (int) $row['topic_poster'],
			time:               (int) $row['topic_time'],
			postsApproved:      (int) $row['topic_posts_approved'],
			lastPostTime:       (int) $row['topic_last_post_time'],
			lastPosterName:     (string) $row['topic_last_poster_name'],
			lastPosterId:       (int) $row['topic_last_poster_id'],
			lastPosterColour:   (string) $row['topic_last_poster_colour'],
			firstPostId:        (int) $row['topic_first_post_id'],
			lastPostId:         (int) $row['topic_last_post_id'],
			visibility:         (int) $row['topic_visibility'],
			firstPosterName:    (string) $row['topic_first_poster_name'],
			firstPosterColour:  (string) $row['topic_first_poster_colour'],
		);
	}
}

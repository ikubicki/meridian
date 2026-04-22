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

use Doctrine\DBAL\ParameterType;
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
			$row = $this->connection->executeQuery(
				'SELECT topic_id, forum_id, topic_title, topic_poster, topic_time,
                        topic_posts_approved, topic_last_post_time, topic_last_poster_name,
                        topic_last_poster_id, topic_last_poster_colour,
                        topic_first_post_id, topic_last_post_id, topic_visibility,
                        topic_first_poster_name, topic_first_poster_colour
                 FROM ' . self::TABLE . '
                 WHERE topic_id = :id
                 LIMIT 1',
				['id' => $id],
			)->fetchAssociative();

			return $row !== false ? $this->hydrate($row) : null;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find topic by ID', previous: $e);
		}
	}

	public function findByForum(int $forumId, PaginationContext $ctx): PaginatedResult
	{
		try {
			$total = (int) $this->connection->executeQuery(
				'SELECT COUNT(*) FROM ' . self::TABLE . '
                 WHERE forum_id = :forumId AND topic_visibility = 1',
				['forumId' => $forumId],
			)->fetchOne();

			$offset = ($ctx->page - 1) * $ctx->perPage;

			$rows = $this->connection->executeQuery(
				'SELECT topic_id, forum_id, topic_title, topic_poster, topic_time,
                        topic_posts_approved, topic_last_post_time, topic_last_poster_name,
                        topic_last_poster_id, topic_last_poster_colour,
                        topic_first_post_id, topic_last_post_id, topic_visibility,
                        topic_first_poster_name, topic_first_poster_colour
                 FROM ' . self::TABLE . '
                 WHERE forum_id = :forumId AND topic_visibility = 1
                 ORDER BY topic_last_post_time DESC
                 LIMIT :limit OFFSET :offset',
				['forumId' => $forumId, 'limit' => $ctx->perPage, 'offset' => $offset],
				['forumId' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
			)->fetchAllAssociative();

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
			$this->connection->executeStatement(
				'INSERT INTO ' . self::TABLE . '
                    (forum_id, topic_title, topic_poster, topic_time,
                     topic_first_poster_name, topic_first_poster_colour,
                     topic_last_poster_id, topic_last_poster_name, topic_last_poster_colour,
                     topic_last_post_subject, topic_last_post_time, topic_visibility)
                 VALUES
                    (:forumId, :title, :posterId, :now,
                     :firstPosterName, :firstPosterColour,
                     :posterId, :posterName, :posterColour,
                     :title, :now, 1)',
				[
					'forumId'           => $request->forumId,
					'title'             => $request->title,
					'posterId'          => $request->actorId,
					'now'               => $now,
					'firstPosterName'   => $request->actorUsername,
					'firstPosterColour' => $request->actorColour,
					'posterName'        => $request->actorUsername,
					'posterColour'      => $request->actorColour,
				],
			);

			return (int) $this->connection->lastInsertId();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to insert topic', previous: $e);
		}
	}

	public function updateFirstLastPost(int $topicId, int $postId): void
	{
		try {
			$this->connection->executeStatement(
				'UPDATE ' . self::TABLE . '
                 SET topic_first_post_id = :postId, topic_last_post_id = :postId
                 WHERE topic_id = :topicId',
				['postId' => $postId, 'topicId' => $topicId],
			);
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
			$this->connection->executeStatement(
				'UPDATE ' . self::TABLE . '
                 SET topic_last_post_id       = :postId,
                     topic_last_poster_id     = :posterId,
                     topic_last_poster_name   = :posterName,
                     topic_last_poster_colour = :posterColour,
                     topic_last_post_time     = :now
                 WHERE topic_id = :topicId',
				[
					'postId'       => $postId,
					'posterId'     => $posterId,
					'posterName'   => $posterName,
					'posterColour' => $posterColour,
					'now'          => $now,
					'topicId'      => $topicId,
				],
			);
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

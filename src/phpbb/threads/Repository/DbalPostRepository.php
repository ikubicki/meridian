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
use phpbb\threads\Contract\PostRepositoryInterface;
use phpbb\threads\DTO\PostDTO;
use phpbb\threads\Entity\Post;
use phpbb\user\DTO\PaginatedResult;

class DbalPostRepository implements PostRepositoryInterface
{
	private const TABLE = 'phpbb_posts';

	public function __construct(
		private readonly \Doctrine\DBAL\Connection $connection,
	) {
	}

	public function findById(int $id): ?Post
	{
		try {
			$row = $this->connection->executeQuery(
				'SELECT post_id, topic_id, forum_id, poster_id, post_time,
                        post_text, post_subject, post_username, poster_ip, post_visibility
                 FROM ' . self::TABLE . '
                 WHERE post_id = :id
                 LIMIT 1',
				['id' => $id],
			)->fetchAssociative();

			return $row !== false ? $this->hydrate($row) : null;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find post by ID', previous: $e);
		}
	}

	public function findByTopic(int $topicId, PaginationContext $ctx): PaginatedResult
	{
		try {
			$total = (int) $this->connection->executeQuery(
				'SELECT COUNT(*) FROM ' . self::TABLE . '
                 WHERE topic_id = :topicId AND post_visibility = 1',
				['topicId' => $topicId],
			)->fetchOne();

			$offset = ($ctx->page - 1) * $ctx->perPage;

			$rows = $this->connection->executeQuery(
				'SELECT post_id, topic_id, forum_id, poster_id, post_time,
                        post_text, post_subject, post_username, poster_ip, post_visibility
                 FROM ' . self::TABLE . '
                 WHERE topic_id = :topicId AND post_visibility = 1
                 ORDER BY post_time ASC
                 LIMIT :limit OFFSET :offset',
				[
					'topicId' => $topicId,
					'limit'   => $ctx->perPage,
					'offset'  => $offset,
				],
				[
					'topicId' => ParameterType::INTEGER,
					'limit'   => ParameterType::INTEGER,
					'offset'  => ParameterType::INTEGER,
				],
			)->fetchAllAssociative();

			$items = array_map(
				fn (array $row) => PostDTO::fromEntity($this->hydrate($row)),
				$rows,
			);

			return new PaginatedResult(
				items:   $items,
				total:   $total,
				page:    $ctx->page,
				perPage: $ctx->perPage,
			);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find posts by topic', previous: $e);
		}
	}

	public function insert(
		int $topicId,
		int $forumId,
		int $posterId,
		string $posterUsername,
		string $posterIp,
		string $content,
		string $subject,
		int $now,
		int $visibility,
	): int {
		try {
			$this->connection->executeStatement(
				'INSERT INTO ' . self::TABLE . '
                    (topic_id, forum_id, poster_id, post_time,
                     post_text, post_subject, post_username, poster_ip, post_visibility)
                 VALUES
                    (:topicId, :forumId, :posterId, :now,
                     :content, :subject, :posterUsername, :posterIp, :visibility)',
				[
					'topicId'        => $topicId,
					'forumId'        => $forumId,
					'posterId'       => $posterId,
					'now'            => $now,
					'content'        => $content,
					'subject'        => $subject,
					'posterUsername' => $posterUsername,
					'posterIp'       => $posterIp,
					'visibility'     => $visibility,
				],
			);

			return (int) $this->connection->lastInsertId();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to insert post', previous: $e);
		}
	}

	private function hydrate(array $row): Post
	{
		return new Post(
			id:         (int) $row['post_id'],
			topicId:    (int) $row['topic_id'],
			forumId:    (int) $row['forum_id'],
			posterId:   (int) $row['poster_id'],
			time:       (int) $row['post_time'],
			text:       (string) $row['post_text'],
			subject:    (string) $row['post_subject'],
			username:   (string) $row['post_username'],
			posterIp:   (string) $row['poster_ip'],
			visibility: (int) $row['post_visibility'],
		);
	}
}

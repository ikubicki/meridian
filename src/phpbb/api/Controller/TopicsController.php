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

namespace phpbb\api\Controller;

use Doctrine\DBAL\Connection;
use phpbb\auth\Contract\AuthorizationServiceInterface;
use phpbb\user\Contract\UserRepositoryInterface;
use phpbb\user\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class TopicsController
{
	private const ANONYMOUS_USER_ID = 1;

	public function __construct(
		private readonly Connection $connection,
		private readonly AuthorizationServiceInterface $authorizationService,
		private readonly UserRepositoryInterface $userRepository,
	) {
	}

	#[Route('/forums/{forumId}/topics', name: 'api_v1_forums_topics_index', methods: ['GET'], defaults: ['_allow_anonymous' => true])]
	public function indexByForum(int $forumId, Request $request): JsonResponse
	{
		/** @var User|null $user */
		$user    = $request->attributes->get('_api_user');
		$checker = $user ?? $this->userRepository->findById(self::ANONYMOUS_USER_ID);

		if ($checker === null || !$this->authorizationService->isGranted($checker, 'f_read', $forumId)) {
			return new JsonResponse(['error' => 'Forbidden', 'status' => 403], 403);
		}

		$page    = max(1, (int) $request->query->get('page', '1'));
		$perPage = 25;
		$offset  = ($page - 1) * $perPage;

		$total = (int) $this->connection->fetchOne(
			'SELECT COUNT(*) FROM phpbb_topics WHERE forum_id = ? AND topic_visibility = 1',
			[$forumId],
		);

		$rows = $this->connection->fetchAllAssociative(
			sprintf(
				'SELECT topic_id, forum_id, topic_title, topic_poster, topic_time,
				        topic_posts_approved, topic_last_post_time, topic_last_poster_name
				 FROM phpbb_topics
				 WHERE forum_id = ? AND topic_visibility = 1
				 ORDER BY topic_last_post_time DESC
				 LIMIT %d OFFSET %d',
				$perPage,
				$offset,
			),
			[$forumId],
		);

		$data = array_map([$this, 'topicRowToArray'], $rows);

		return new JsonResponse([
			'data' => $data,
			'meta' => [
				'total'    => $total,
				'page'     => $page,
				'perPage'  => $perPage,
				'lastPage' => max(1, (int) ceil($total / $perPage)),
			],
		]);
	}

	#[Route('/topics/{topicId}', name: 'api_v1_topics_show', methods: ['GET'], defaults: ['_allow_anonymous' => true])]
	public function show(int $topicId, Request $request): JsonResponse
	{
		$row = $this->connection->fetchAssociative(
			'SELECT topic_id, forum_id, topic_title, topic_poster, topic_time,
			        topic_posts_approved, topic_last_post_time, topic_last_poster_name, topic_visibility
			 FROM phpbb_topics WHERE topic_id = ?',
			[$topicId],
		);

		if ($row === false || (int) $row['topic_visibility'] !== 1) {
			return new JsonResponse(['error' => 'Topic not found', 'status' => 404], 404);
		}

		$forumId = (int) $row['forum_id'];

		/** @var User|null $user */
		$user    = $request->attributes->get('_api_user');
		$checker = $user ?? $this->userRepository->findById(self::ANONYMOUS_USER_ID);

		if ($checker === null || !$this->authorizationService->isGranted($checker, 'f_read', $forumId)) {
			return new JsonResponse(['error' => 'Forbidden', 'status' => 403], 403);
		}

		return new JsonResponse(['data' => $this->topicRowToArray($row)]);
	}

	#[Route('/forums/{forumId}/topics', name: 'api_v1_forums_topics_create', methods: ['POST'])]
	public function create(int $forumId, Request $request): JsonResponse
	{
		/** @var User|null $user */
		$user = $request->attributes->get('_api_user');

		if ($user === null) {
			return new JsonResponse(['error' => 'Authentication required', 'status' => 401], 401);
		}

		if (!$this->authorizationService->isGranted($user, 'f_post', $forumId)) {
			return new JsonResponse(['error' => 'Forbidden', 'status' => 403], 403);
		}

		$body    = json_decode($request->getContent(), true) ?? [];
		$title   = trim((string) ($body['title'] ?? ''));
		$content = trim((string) ($body['content'] ?? ''));

		if ($title === '') {
			return new JsonResponse(['error' => 'Title is required', 'status' => 400], 400);
		}

		$now = time();

		$this->connection->beginTransaction();

		try {
			$this->connection->insert('phpbb_topics', [
				'forum_id'                  => $forumId,
				'topic_title'               => $title,
				'topic_poster'              => $user->id,
				'topic_time'                => $now,
				'topic_first_poster_name'   => $user->username,
				'topic_first_poster_colour' => $user->colour,
				'topic_last_poster_id'      => $user->id,
				'topic_last_poster_name'    => $user->username,
				'topic_last_poster_colour'  => $user->colour,
				'topic_last_post_subject'   => $title,
				'topic_last_post_time'      => $now,
				'topic_visibility'          => 1,
			]);

			$topicId = (int) $this->connection->lastInsertId();

			$this->connection->insert('phpbb_posts', [
				'topic_id'        => $topicId,
				'forum_id'        => $forumId,
				'poster_id'       => $user->id,
				'post_time'       => $now,
				'post_text'       => $content,
				'post_subject'    => $title,
				'post_username'   => $user->username,
				'poster_ip'       => $request->getClientIp() ?? '127.0.0.1',
				'post_visibility' => 1,
			]);

			$postId = (int) $this->connection->lastInsertId();

			$this->connection->update('phpbb_topics', [
				'topic_first_post_id' => $postId,
				'topic_last_post_id'  => $postId,
			], ['topic_id' => $topicId]);

			$this->connection->commit();
		} catch (\Throwable $e) {
			$this->connection->rollBack();

			return new JsonResponse(['error' => 'Internal server error', 'status' => 500], 500);
		}

		return new JsonResponse([
			'data' => [
				'id'        => $topicId,
				'title'     => $title,
				'forumId'   => $forumId,
				'authorId'  => $user->id,
				'firstPost' => ['id' => $postId, 'content' => $content],
			],
		], 201);
	}

	/** @param array<string, mixed> $row */
	private function topicRowToArray(array $row): array
	{
		return [
			'id'             => (int) $row['topic_id'],
			'title'          => $row['topic_title'],
			'forumId'        => (int) $row['forum_id'],
			'authorId'       => (int) $row['topic_poster'],
			'postCount'      => (int) $row['topic_posts_approved'],
			'lastPosterName' => $row['topic_last_poster_name'],
			'lastPostTime'   => $row['topic_last_post_time'],
			'createdAt'      => $row['topic_time'],
		];
	}
}

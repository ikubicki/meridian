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
use phpbb\user\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class PostsController
{
	public function __construct(
		private readonly Connection $connection,
		private readonly AuthorizationServiceInterface $authorizationService,
	) {
	}

	#[Route('/topics/{topicId}/posts', name: 'api_v1_topics_posts_create', methods: ['POST'])]
	public function create(int $topicId, Request $request): JsonResponse
	{
		/** @var User|null $user */
		$user = $request->attributes->get('_api_user');

		if ($user === null) {
			return new JsonResponse(['error' => 'Authentication required', 'status' => 401], 401);
		}

		$topic = $this->connection->fetchAssociative(
			'SELECT topic_id, forum_id, topic_status FROM phpbb_topics WHERE topic_id = ?',
			[$topicId],
		);

		if ($topic === false) {
			return new JsonResponse(['error' => 'Topic not found', 'status' => 404], 404);
		}

		$forumId = (int) $topic['forum_id'];

		if (!$this->authorizationService->isGranted($user, 'f_reply', $forumId)) {
			return new JsonResponse(['error' => 'Forbidden', 'status' => 403], 403);
		}

		$body    = json_decode($request->getContent(), true) ?? [];
		$content = trim((string) ($body['content'] ?? ''));

		if ($content === '') {
			return new JsonResponse(['error' => 'Content is required', 'status' => 400], 400);
		}

		$now = time();

		$this->connection->beginTransaction();

		try {
			$this->connection->insert('phpbb_posts', [
				'topic_id'        => $topicId,
				'forum_id'        => $forumId,
				'poster_id'       => $user->id,
				'post_time'       => $now,
				'post_text'       => $content,
				'post_subject'    => 'Re: post',
				'post_username'   => $user->username,
				'poster_ip'       => $request->getClientIp() ?? '127.0.0.1',
				'post_visibility' => 1,
			]);

			$postId = (int) $this->connection->lastInsertId();

			$this->connection->update('phpbb_topics', [
				'topic_last_post_id'      => $postId,
				'topic_last_poster_id'    => $user->id,
				'topic_last_poster_name'  => $user->username,
				'topic_last_poster_colour' => $user->colour,
				'topic_last_post_time'    => $now,
			], ['topic_id' => $topicId]);

			$this->connection->commit();
		} catch (\Throwable $e) {
			$this->connection->rollBack();

			return new JsonResponse(['error' => 'Internal server error', 'status' => 500], 500);
		}

		return new JsonResponse([
			'data' => [
				'id'       => $postId,
				'topicId'  => $topicId,
				'forumId'  => $forumId,
				'authorId' => $user->id,
				'content'  => $content,
			],
		], 201);
	}
}

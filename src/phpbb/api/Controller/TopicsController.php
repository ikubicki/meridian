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

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class TopicsController
{
	/** @var array<int, array<string, mixed>> */
	private const MOCK_TOPICS = [
		[
			'id'          => 1,
			'title'       => 'Hello World',
			'forumId'     => 1,
			'authorId'    => 2,
			'postCount'   => 3,
			'visibility'  => 1,
			'accessLevel' => 0, // 0=public, 1=login required, 2=password required
			'createdAt'   => '2026-04-01T12:00:00Z',
		],
		[
			'id'          => 2,
			'title'       => 'Welcome to phpBB4',
			'forumId'     => 1,
			'authorId'    => 1,
			'postCount'   => 7,
			'visibility'  => 1,
			'accessLevel' => 0,
			'createdAt'   => '2026-04-02T09:30:00Z',
		],
		[
			'id'          => 3,
			'title'       => 'Members Only Discussion',
			'forumId'     => 2,
			'authorId'    => 1,
			'postCount'   => 2,
			'visibility'  => 1,
			'accessLevel' => 1, // login required
			'createdAt'   => '2026-04-03T14:00:00Z',
		],
		[
			'id'          => 4,
			'title'       => 'Password Protected Post',
			'forumId'     => 2,
			'authorId'    => 1,
			'postCount'   => 1,
			'visibility'  => 1,
			'accessLevel' => 2, // password required
			'createdAt'   => '2026-04-04T10:00:00Z',
		],
	];

	#[Route('/forums/{forumId}/topics', name: 'api_v1_forums_topics_index', methods: ['GET'])]
	public function indexByForum(int $forumId, Request $request): JsonResponse
	{
		// TODO: Replace with real ThreadsService::listTopics() call
		$page    = max(1, (int) $request->query->get('page', '1'));
		$perPage = 25;

		$topics = array_values(array_filter(
			self::MOCK_TOPICS,
			static fn (array $t): bool => $t['forumId'] === $forumId,
		));

		return new JsonResponse([
			'data' => $topics,
			'meta' => [
				'total'    => count($topics),
				'page'     => $page,
				'perPage'  => $perPage,
				'lastPage' => max(1, (int) ceil(count($topics) / $perPage)),
			],
		]);
	}

	#[Route('/topics/{topicId}', name: 'api_v1_topics_show', methods: ['GET'])]
	public function show(int $topicId, Request $request): JsonResponse
	{
		// TODO: Replace with real ThreadsService::getTopic() call
		foreach (self::MOCK_TOPICS as $topic) {
			if ($topic['id'] === $topicId) {
				if ($topic['accessLevel'] > 0 && $request->attributes->get('_api_token') === null) {
					return new JsonResponse(
						['error' => 'Authentication required', 'status' => 401],
						401,
					);
				}

				return new JsonResponse(['data' => $topic]);
			}
		}

		return new JsonResponse(['error' => 'Topic not found', 'status' => 404], 404);
	}
}

<?php
/**
 *
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

namespace phpbb\api\v1\controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Topics mock controller for the Forum REST API (Phase 1 — hardcoded data).
 */
class topics
{
	private $mock_topics = [
		[
			'id'         => 1,
			'forum_id'   => 1,
			'title'      => 'Welcome to phpBB!',
			'author'     => 'admin',
			'posts'      => 3,
			'views'      => 120,
			'created_at' => '2026-01-01T10:00:00Z',
			'last_post'  => '2026-04-10T14:22:00Z',
		],
		[
			'id'         => 2,
			'forum_id'   => 1,
			'title'      => 'How to configure your board',
			'author'     => 'admin',
			'posts'      => 7,
			'views'      => 340,
			'created_at' => '2026-01-05T12:00:00Z',
			'last_post'  => '2026-04-15T09:00:00Z',
		],
		[
			'id'         => 3,
			'forum_id'   => 2,
			'title'      => 'phpBB API Phase 1 released',
			'author'     => 'admin',
			'posts'      => 1,
			'views'      => 55,
			'created_at' => '2026-04-01T09:00:00Z',
			'last_post'  => '2026-04-01T09:00:00Z',
		],
		[
			'id'         => 4,
			'forum_id'   => 3,
			'title'      => 'Installation issue on Docker',
			'author'     => 'jane_smith',
			'posts'      => 5,
			'views'      => 88,
			'created_at' => '2026-04-14T16:00:00Z',
			'last_post'  => '2026-04-17T07:30:00Z',
		],
	];

	/**
	 * GET /api/v1/topics
	 *
	 * Optional query param: ?forum_id=N to filter by forum.
	 *
	 * @param Request $request
	 * @return JsonResponse
	 */
	public function index(Request $request)
	{
		$forum_id = $request->query->get('forum_id');
		$topics   = $this->mock_topics;

		if ($forum_id !== null)
		{
			$forum_id = (int) $forum_id;
			$topics   = array_values(array_filter($topics, function($t) use ($forum_id) {
				return $t['forum_id'] === $forum_id;
			}));
		}

		return new JsonResponse([
			'topics' => $topics,
			'total'  => count($topics),
		]);
	}

	/**
	 * GET /api/v1/topics/{id}
	 *
	 * @param Request $request
	 * @param int     $id
	 * @return JsonResponse
	 */
	public function show(Request $request, $id)
	{
		$id = (int) $id;

		foreach ($this->mock_topics as $topic)
		{
			if ($topic['id'] === $id)
			{
				return new JsonResponse([
					'topic' => array_merge($topic, [
						'content' => 'This is the body of topic #' . $id . '. Phase 2 will fetch real post content from the database.',
					]),
				]);
			}
		}

		return new JsonResponse(['error' => 'Topic not found', 'status' => 404], 404);
	}
}

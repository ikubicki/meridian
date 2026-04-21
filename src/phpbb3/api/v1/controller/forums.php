<?php
/**
 *
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com\>
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
 * Forums mock controller for the Forum REST API (Phase 1 — hardcoded data).
 */
class forums
{
	/**
	 * GET /api/v1/forums
	 *
	 * @param Request $request
	 * @return JsonResponse
	 */
	public function index(Request $request)
	{
		$token = $request->attributes->get('_api_token');

		return new JsonResponse([
			'requester' => [
				'user_id'  => $token->user_id ?? null,
				'username' => $token->username ?? null,
			],
			'forums' => [
				[
					'id'          => 1,
					'name'        => 'General Discussion',
					'description' => 'General talk about everything',
					'topics'      => 42,
					'posts'       => 256,
					'last_post'   => [
						'id'       => 312,
						'author'   => 'john_doe',
						'subject'  => 'Re: Hello World',
						'time'     => '2026-04-16T18:00:00Z',
					],
				],
				[
					'id'          => 2,
					'name'        => 'Announcements',
					'description' => 'Important announcements from the team',
					'topics'      => 5,
					'posts'       => 5,
					'last_post'   => [
						'id'       => 10,
						'author'   => 'admin',
						'subject'  => 'phpBB API Phase 1 released',
						'time'     => '2026-04-01T09:00:00Z',
					],
				],
				[
					'id'          => 3,
					'name'        => 'Support',
					'description' => 'Ask for help here',
					'topics'      => 18,
					'posts'       => 74,
					'last_post'   => [
						'id'       => 305,
						'author'   => 'jane_smith',
						'subject'  => 'Re: Installation issue',
						'time'     => '2026-04-17T07:30:00Z',
					],
				],
			],
			'total' => 3,
		]);
	}

	/**
	 * GET /api/v1/forums/{id}
	 *
	 * Returns topics belonging to the given forum — same data as /topics?forum_id={id}.
	 *
	 * @param Request $request
	 * @param int     $id
	 * @return JsonResponse
	 */
	public function topics(Request $request, $id)
	{
		$topics_controller = new topics();
		$request->query->set('forum_id', $id);
		return $topics_controller->index($request);
	}
}

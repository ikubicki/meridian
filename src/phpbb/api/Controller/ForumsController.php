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

declare(strict_types=1);

namespace phpbb\api\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class ForumsController
{
	/** @var array<int, array<string, mixed>> */
	private const MOCK_FORUMS = [
		[
			'id'          => 1,
			'title'       => 'General Discussion',
			'description' => 'Talk about anything.',
			'topicCount'  => 42,
			'postCount'   => 378,
			'parentId'    => null,
		],
		[
			'id'          => 2,
			'title'       => 'News & Announcements',
			'description' => 'Official announcements from the team.',
			'topicCount'  => 7,
			'postCount'   => 21,
			'parentId'    => null,
		],
	];

	#[Route('/forums', name: 'api_v1_forums_index', methods: ['GET'])]
	public function index(): JsonResponse
	{
		// TODO: Replace with real HierarchyService::listForums() call
		return new JsonResponse([
			'data' => self::MOCK_FORUMS,
			'meta' => ['total' => count(self::MOCK_FORUMS)],
		]);
	}

	#[Route('/forums/{forumId}', name: 'api_v1_forums_show', methods: ['GET'])]
	public function show(int $forumId): JsonResponse
	{
		// TODO: Replace with real HierarchyService::getForum() call
		foreach (self::MOCK_FORUMS as $forum) {
			if ($forum['id'] === $forumId) {
				return new JsonResponse(['data' => $forum]);
			}
		}

		return new JsonResponse(['error' => 'Forum not found', 'status' => 404], 404);
	}
}

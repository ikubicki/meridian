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

use phpbb\api\DTO\PaginationContext;
use phpbb\search\Contract\SearchServiceInterface;
use phpbb\search\DTO\SearchQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SearchController
{
	public function __construct(
		private readonly SearchServiceInterface $searchService,
	) {
	}

	#[Route('/search', name: 'api_v1_search_index', methods: ['GET'])]
	public function search(Request $request): JsonResponse
	{
		/** @var \phpbb\user\Entity\User|null $user */
		$user = $request->attributes->get('_api_user');

		if ($user === null) {
			return new JsonResponse(['error' => 'Authentication required', 'status' => 401], Response::HTTP_UNAUTHORIZED);
		}

		$q = trim((string) $request->query->get('q', ''));
		if ($q === '') {
			return new JsonResponse(['error' => 'Query parameter "q" is required.', 'status' => 400], Response::HTTP_BAD_REQUEST);
		}

		$forumId = $request->query->has('forum_id') ? (int) $request->query->get('forum_id') : null;
		$topicId = $request->query->has('topic_id') ? (int) $request->query->get('topic_id') : null;
		$userId  = $request->query->has('user_id') ? (int) $request->query->get('user_id') : null;

		$page    = max(1, (int) $request->query->get('page', 1));
		$perPage = min(50, max(1, (int) $request->query->get('perPage', 25)));
		$ctx     = new PaginationContext($page, $perPage);

		$sortBy   = $request->query->getString('sort_by', 'date_desc');
		$searchIn = $request->query->getString('search_in', 'both');
		$dateFrom = $request->query->has('date_from') ? (int) $request->query->get('date_from') : null;
		$dateTo   = $request->query->has('date_to') ? (int) $request->query->get('date_to') : null;

		$allowedSortBy   = ['date_desc', 'date_asc', 'relevance'];
		$allowedSearchIn = ['both', 'posts', 'titles', 'first_post'];
		if (!in_array($sortBy, $allowedSortBy, true)) {
			$sortBy = 'date_desc';
		}
		if (!in_array($searchIn, $allowedSearchIn, true)) {
			$searchIn = 'both';
		}

		$searchQuery = new SearchQuery(
			keywords: $q,
			forumId:  $forumId,
			topicId:  $topicId,
			userId:   $userId,
			sortBy:   $sortBy,
			searchIn: $searchIn,
			dateFrom: $dateFrom,
			dateTo:   $dateTo,
		);

		$result = $this->searchService->search($searchQuery, $ctx);

		return new JsonResponse([
			'data' => array_map(fn ($item) => $item->toArray(), $result->items),
			'meta' => [
				'total'    => $result->total,
				'page'     => $result->page,
				'perPage'  => $result->perPage,
				'lastPage' => max(1, $result->totalPages()),
			],
		]);
	}
}

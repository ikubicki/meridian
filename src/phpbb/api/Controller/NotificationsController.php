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
use phpbb\notifications\Contract\NotificationServiceInterface;
use phpbb\notifications\DTO\NotificationDTO;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class NotificationsController
{
	public function __construct(
		private readonly NotificationServiceInterface $service,
		private readonly EventDispatcherInterface $dispatcher,
	) {
	}

	#[Route('/notifications/count', name: 'api_v1_notifications_count', methods: ['GET'])]
	public function count(Request $request): JsonResponse
	{
		$user = $request->attributes->get('_api_user');

		if ($user === null) {
			return new JsonResponse(['error' => 'Authentication required'], 401);
		}

		try {
			$userId = $user->id;

			$response = new JsonResponse();
			$response->headers->set('X-Poll-Interval', '30');

			// Known trade-off: Last-Modified is derived from MAX(notification_time).
			// It does NOT update on markRead — clients using If-Modified-Since may
			// receive 304 with stale count for up to 30s after a mark-read.
			// Tag-cache invalidation ensures freshness for clients omitting If-Modified-Since.
			$lastModifiedTs = $this->service->getLastModified($userId);

			if ($lastModifiedTs !== null) {
				$response->setLastModified(new \DateTimeImmutable('@' . $lastModifiedTs));
			}

			if ($response->isNotModified($request)) {
				return $response;
			}

			$count = $this->service->getUnreadCount($userId);
			$response->setData(['data' => ['unread' => $count]]);

			return $response;
		} catch (\Throwable) {
			return new JsonResponse(['error' => 'Internal server error'], 500);
		}
	}

	#[Route('/notifications/read', name: 'api_v1_notifications_mark_all_read', methods: ['POST'])]
	public function markAllRead(Request $request): JsonResponse
	{
		$user = $request->attributes->get('_api_user');

		if ($user === null) {
			return new JsonResponse(['error' => 'Authentication required'], 401);
		}

		try {
			$userId = $user->id;
			$events = $this->service->markAllRead($userId);
			$events->dispatch($this->dispatcher);

			return new JsonResponse(null, 204);
		} catch (\Throwable) {
			return new JsonResponse(['error' => 'Internal server error'], 500);
		}
	}

	#[Route('/notifications/{id}/read', name: 'api_v1_notifications_mark_read', methods: ['POST'], requirements: ['id' => '\d+'])]
	public function markRead(int $id, Request $request): JsonResponse
	{
		$user = $request->attributes->get('_api_user');

		if ($user === null) {
			return new JsonResponse(['error' => 'Authentication required'], 401);
		}

		try {
			$userId = $user->id;
			$events = $this->service->markRead($id, $userId);
			$events->dispatch($this->dispatcher);

			return new JsonResponse(null, 204);
		} catch (\InvalidArgumentException) {
			return new JsonResponse(['error' => 'Notification not found'], 404);
		} catch (\Throwable) {
			return new JsonResponse(['error' => 'Internal server error'], 500);
		}
	}

	#[Route('/notifications', name: 'api_v1_notifications_index', methods: ['GET'])]
	public function index(Request $request): JsonResponse
	{
		$user = $request->attributes->get('_api_user');

		if ($user === null) {
			return new JsonResponse(['error' => 'Authentication required'], 401);
		}

		try {
			$userId = $user->id;
			$ctx    = PaginationContext::fromQuery($request->query);
			$result = $this->service->getNotifications($userId, $ctx);
			$items  = array_map(fn (NotificationDTO $dto) => $dto->toArray(), $result->items);

			return new JsonResponse([
				'data' => $items,
				'meta' => [
					'total'    => $result->total,
					'page'     => $ctx->page,
					'perPage'  => $ctx->perPage,
					'lastPage' => max(1, $result->totalPages()),
				],
			]);
		} catch (\Throwable) {
			return new JsonResponse(['error' => 'Internal server error'], 500);
		}
	}
}

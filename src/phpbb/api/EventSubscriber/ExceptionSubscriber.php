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

namespace phpbb\api\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Converts unhandled exceptions on /api/* paths into JSON error responses.
 *
 * Priority 10 ensures this fires before any Twig-dependent forum subscriber.
 */
class ExceptionSubscriber implements EventSubscriberInterface
{
	public static function getSubscribedEvents(): array
	{
		return [
			KernelEvents::EXCEPTION => ['onKernelException', 10],
		];
	}

	public function onKernelException(ExceptionEvent $event): void
	{
		$path = $event->getRequest()->getPathInfo();

		if (!str_starts_with($path, '/api/')) {
			return;
		}

		$exception = $event->getThrowable();

		if ($exception instanceof HttpExceptionInterface) {
			$status  = $exception->getStatusCode();
			$headers = $exception->getHeaders();
			$message = $exception->getMessage() ?: $this->defaultMessage($status);
		} else {
			$status  = 500;
			$headers = [];
			$message = 'An unexpected error occurred.';
		}

		$response = new JsonResponse(['error' => $message, 'status' => $status], $status, $headers);
		$event->setResponse($response);
		$event->stopPropagation();
	}

	private function defaultMessage(int $status): string
	{
		return match ($status) {
			400     => 'Bad Request',
			401     => 'Unauthorized',
			403     => 'Forbidden',
			404     => 'Not Found',
			409     => 'Conflict',
			422     => 'Unprocessable Entity',
			500     => 'Internal Server Error',
			default => 'Error',
		};
	}
}

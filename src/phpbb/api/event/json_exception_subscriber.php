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

namespace phpbb\api\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Converts kernel exceptions to JSON responses for the REST API.
 *
 * Registered at priority 10 so it fires before the HTML-rendering
 * kernel_exception_subscriber (priority 0). Calls stopPropagation()
 * to prevent the HTML subscriber from overriding the JSON response.
 *
 * Guarded by path prefix check — non-API routes fall through to the
 * Twig-based exception handler as normal.
 */
class json_exception_subscriber implements EventSubscriberInterface
{
	/** @var bool */
	private $debug;

	/**
	 * @param bool $debug When true, includes the exception trace in the response body
	 */
	public function __construct($debug = false)
	{
		$this->debug = (bool) $debug;
	}

	/**
	 * Transform any kernel exception into a JSON response.
	 *
	 * Only intercepts requests to API paths; non-API routes fall through to
	 * the Twig-based kernel_exception_subscriber as normal.
	 *
	 * @param GetResponseForExceptionEvent $event
	 * @return void
	 */
	public function on_kernel_exception(GetResponseForExceptionEvent $event)
	{
		$path = $event->getRequest()->getPathInfo();

		// Only handle API paths — let HTML subscriber handle forum routes
		if (strpos($path, '/api/') !== 0 && strpos($path, '/adm/api/') !== 0 && strpos($path, '/install/api/') !== 0)
		{
			return;
		}

		$exception = $event->getException();

		$status = ($exception instanceof HttpExceptionInterface)
			? $exception->getStatusCode()
			: 500;

		$data = [
			'error'  => $exception->getMessage(),
			'status' => $status,
		];

		if ($this->debug)
		{
			$data['trace'] = $exception->getTraceAsString();
		}

		$response = new JsonResponse($data, $status);

		if ($exception instanceof HttpExceptionInterface)
		{
			$response->headers->add($exception->getHeaders());
		}

		$event->setResponse($response);
		$event->stopPropagation();
	}

	/**
	 * {@inheritdoc}
	 */
	public static function getSubscribedEvents()
	{
		return [
			KernelEvents::EXCEPTION => ['on_kernel_exception', 10],
		];
	}
}

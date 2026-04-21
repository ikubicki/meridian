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

namespace phpbb\core;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * Shared HTTP application wrapper for all phpBB REST API entry points.
 *
 * Composes Symfony HttpKernel via delegation (not inheritance) so that
 * the same class can be registered as multiple DI services with different IDs.
 */
class Application implements HttpKernelInterface, TerminableInterface
{
	/** @var HttpKernel */
	private $kernel;

	/** @var ContainerInterface */
	private $container;

	/**
	 * @param HttpKernel         $kernel    The shared forum/installer HttpKernel service
	 * @param ContainerInterface $container The DI container (used to fetch symfony_request)
	 */
	public function __construct(HttpKernel $kernel, ContainerInterface $container)
	{
		$this->kernel    = $kernel;
		$this->container = $container;
	}

	/**
	 * {@inheritdoc}
	 */
	public function handle(Request $request, $type = self::MASTER_REQUEST, $catch = true)
	{
		return $this->kernel->handle($request, $type, $catch);
	}

	/**
	 * {@inheritdoc}
	 */
	public function terminate(Request $request, Response $response)
	{
		$this->kernel->terminate($request, $response);
	}

	/**
	 * Fetch the current request from the DI container, dispatch it through
	 * the kernel, send the response, then run terminate subscribers.
	 *
	 * @return void
	 */
	public function run()
	{
		/** @var Request $request */
		$request  = $this->container->get('symfony_request');
		$response = $this->handle($request);
		$response->send();
		$this->terminate($request, $response);
	}
}

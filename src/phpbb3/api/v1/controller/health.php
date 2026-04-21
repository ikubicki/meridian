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
 * Health check controller for the Forum REST API.
 */
class health
{
	/**
	 * GET /api/v1/health
	 *
	 * @param Request $request
	 * @return JsonResponse
	 */
	public function index(Request $request)
	{
		return new JsonResponse([
			'status'  => 'ok',
			'api'     => 'phpBB Forum API',
			'version' => '1.0.0-dev',
		]);
	}
}

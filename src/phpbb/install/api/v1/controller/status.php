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

namespace phpbb\install\api\v1\controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Installation status controller for the Installer REST API (Phase 1 — hardcoded data).
 */
class status
{
	/**
	 * GET /install/api/v1/status
	 *
	 * @param Request $request
	 * @return JsonResponse
	 */
	public function index(Request $request)
	{
		return new JsonResponse([
			'installed' => false,
			'version'   => '3.3.x-dev',
		]);
	}
}

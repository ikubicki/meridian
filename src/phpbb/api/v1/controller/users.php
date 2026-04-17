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
 * Users mock controller for the Forum REST API (Phase 1 — hardcoded data).
 */
class users
{
	/**
	 * GET /api/v1/users/me
	 *
	 * @param Request $request
	 * @return JsonResponse
	 */
	public function me(Request $request)
	{
		return new JsonResponse([
			'user' => [
				'id'       => 0,
				'username' => 'guest',
				'email'    => '',
				'role'     => 'guest',
			],
		]);
	}
}

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

namespace phpbb\admin\api\v1\controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Users mock controller for the Admin REST API (Phase 1 — hardcoded data).
 */
class users
{
	/**
	 * GET /adm/api/v1/users
	 *
	 * @param Request $request
	 * @return JsonResponse
	 */
	public function index(Request $request)
	{
		return new JsonResponse([
			'users' => [
				[
					'id'       => 2,
					'username' => 'admin',
					'email'    => 'admin@example.com',
					'role'     => 'administrator',
				],
			],
		]);
	}
}

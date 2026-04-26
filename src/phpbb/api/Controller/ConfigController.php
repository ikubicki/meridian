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

use phpbb\config\Contract\ConfigServiceInterface;
use phpbb\user\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class ConfigController
{
	public function __construct(
		private readonly ConfigServiceInterface $configService,
	) {
	}

	#[Route('/config', name: 'api_v1_config_index', methods: ['GET'])]
	public function index(Request $request): JsonResponse
	{
		$authResponse = $this->requireAdmin($request);
		if ($authResponse !== null) {
			return $authResponse;
		}

		$all = $this->configService->getAll();

		$data = [];
		foreach ($all as $k => $v) {
			$data[] = ['key' => $k, 'value' => $v, 'isDynamic' => false];
		}

		return new JsonResponse(['data' => $data, 'meta' => ['total' => count($data)]]);
	}

	#[Route('/config/{key}', name: 'api_v1_config_show', methods: ['GET'])]
	public function show(string $key, Request $request): JsonResponse
	{
		$authResponse = $this->requireAdmin($request);
		if ($authResponse !== null) {
			return $authResponse;
		}

		$value = $this->configService->get($key, '__NOT_FOUND__');

		if ($value === '__NOT_FOUND__') {
			return new JsonResponse(['error' => 'Config key not found', 'status' => 404], 404);
		}

		return new JsonResponse(['data' => ['key' => $key, 'value' => $value, 'isDynamic' => false]]);
	}

	#[Route('/config/{key}', name: 'api_v1_config_update', methods: ['PUT'])]
	public function update(string $key, Request $request): JsonResponse
	{
		$authResponse = $this->requireAdmin($request);
		if ($authResponse !== null) {
			return $authResponse;
		}

		$body = json_decode($request->getContent(), true) ?? [];

		if (!isset($body['value'])) {
			return new JsonResponse(['error' => "Field 'value' is required", 'status' => 400], 400);
		}

		$isDynamic = (bool) ($body['isDynamic'] ?? false);

		$this->configService->set($key, (string) $body['value'], $isDynamic);

		return new JsonResponse(['data' => ['key' => $key, 'value' => (string) $body['value'], 'isDynamic' => $isDynamic]]);
	}

	#[Route('/config/{key}', name: 'api_v1_config_delete', methods: ['DELETE'])]
	public function delete(string $key, Request $request): JsonResponse
	{
		$authResponse = $this->requireAdmin($request);
		if ($authResponse !== null) {
			return $authResponse;
		}

		$affected = $this->configService->delete($key);

		if ($affected === 0) {
			return new JsonResponse(['error' => 'Config key not found', 'status' => 404], 404);
		}

		return new JsonResponse(null, 204);
	}

	private function requireAdmin(Request $request): ?JsonResponse
	{
		/** @var User|null $user */
		$user = $request->attributes->get('_api_user');

		if ($user === null || $request->attributes->get('_api_elevated') !== true) {
			return new JsonResponse(['error' => 'Elevated token required', 'status' => 401], 401);
		}

		return null;
	}

	private function getActorId(Request $request): int
	{
		/** @var User|null $user */
		$user = $request->attributes->get('_api_user');

		return $user?->id ?? 0;
	}
}

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

namespace phpbb\Tests\api\Controller;

use phpbb\api\Controller\ForumsController;
use phpbb\hierarchy\Contract\HierarchyServiceInterface;
use phpbb\hierarchy\DTO\ForumDTO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

class ForumsControllerTest extends TestCase
{
	private ForumsController $controller;
	private HierarchyServiceInterface $hierarchyService;

	protected function setUp(): void
	{
		$this->hierarchyService = $this->createMock(HierarchyServiceInterface::class);
		$dispatcher             = $this->createMock(EventDispatcherInterface::class);
		$this->controller       = new ForumsController($this->hierarchyService, $dispatcher);
	}

	private function makeForumDto(int $id, string $name): ForumDTO
	{
		return new ForumDTO(
			id: $id,
			name: $name,
			description: '',
			parentId: 0,
			type: 1,
			status: 0,
			leftId: 1,
			rightId: 2,
			displayOnIndex: true,
			topicsApproved: 0,
			postsApproved: 0,
			lastPostId: 0,
			lastPostTime: 0,
			lastPosterName: '',
			link: '',
			parents: [],
		);
	}

	#[Test]
	public function indexReturnsDataEnvelopeWithMeta(): void
	{
		$this->hierarchyService
			->method('listForums')
			->willReturn([
				$this->makeForumDto(1, 'General Discussion'),
				$this->makeForumDto(2, 'News & Announcements'),
			]);

		$response = $this->controller->index(new Request());

		self::assertSame(200, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertArrayHasKey('data', $body);
		self::assertArrayHasKey('meta', $body);
		self::assertArrayHasKey('total', $body['meta']);
		self::assertIsArray($body['data']);
		self::assertNotEmpty($body['data']);
	}

	#[Test]
	public function showReturnsForumDataForExistingId(): void
	{
		$this->hierarchyService
			->method('getForum')
			->with(1)
			->willReturn($this->makeForumDto(1, 'General Discussion'));

		$response = $this->controller->show(1);

		self::assertSame(200, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertArrayHasKey('data', $body);
		self::assertSame(1, $body['data']['id']);
		self::assertArrayHasKey('title', $body['data']);
	}

	#[Test]
	public function showReturns404ForNonExistingForum(): void
	{
		$this->hierarchyService
			->method('getForum')
			->with(999)
			->willThrowException(new \InvalidArgumentException('Forum 999 not found'));

		$response = $this->controller->show(999);

		self::assertSame(404, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertArrayHasKey('error', $body);
		self::assertSame(404, $body['status']);
	}
}

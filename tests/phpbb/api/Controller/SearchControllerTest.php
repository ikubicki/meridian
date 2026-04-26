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

use phpbb\api\Controller\SearchController;
use phpbb\api\DTO\PaginationContext;
use phpbb\search\Contract\SearchServiceInterface;
use phpbb\search\DTO\SearchQuery;
use phpbb\search\DTO\SearchResultDTO;
use phpbb\user\DTO\PaginatedResult;
use phpbb\user\Entity\User;
use phpbb\user\Enum\UserType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class SearchControllerTest extends TestCase
{
	private SearchServiceInterface&MockObject $searchService;
	private SearchController $controller;

	protected function setUp(): void
	{
		$this->searchService = $this->createMock(SearchServiceInterface::class);
		$this->controller    = new SearchController($this->searchService);
	}

	private function makeUser(int $id = 2): User
	{
		return new User(
			id:             $id,
			type:           UserType::Normal,
			username:       'testuser',
			usernameClean:  'testuser',
			email:          '',
			passwordHash:   '',
			colour:         '',
			defaultGroupId: 1,
			avatarUrl:      '',
			registeredAt:   new \DateTimeImmutable('2020-01-01'),
			lastmark:       new \DateTimeImmutable('2020-01-01'),
			posts:          0,
			lastPostTime:   null,
			isNew:          false,
			rank:           0,
			registrationIp: '127.0.0.1',
			loginAttempts:  0,
			inactiveReason: null,
			formSalt:       '',
			activationKey:  '',
		);
	}

	private function makeSearchResult(): SearchResultDTO
	{
		return new SearchResultDTO(
			postId:     42,
			topicId:    10,
			forumId:    2,
			subject:    'Test post subject',
			excerpt:    'This is an excerpt.',
			postedAt:   1700000000,
			topicTitle: 'Test topic title',
			forumName:  'Test forum name',
		);
	}

	private function makeEmptyResult(int $page = 1, int $perPage = 25): PaginatedResult
	{
		return new PaginatedResult(items: [], total: 0, page: $page, perPage: $perPage);
	}

	#[Test]
	public function searchReturns401WhenUserIsNotAuthenticated(): void
	{
		$request  = new Request(['q' => 'hello']);
		$response = $this->controller->search($request);

		$this->assertSame(401, $response->getStatusCode());
	}

	#[Test]
	public function searchReturns400WhenQueryParamIsMissing(): void
	{
		$request = new Request();
		$request->attributes->set('_api_user', $this->makeUser());

		$response = $this->controller->search($request);

		$this->assertSame(400, $response->getStatusCode());
		$body = json_decode($response->getContent(), true);
		$this->assertArrayHasKey('error', $body);
	}

	#[Test]
	public function searchReturns400WhenQueryParamIsBlank(): void
	{
		$request = new Request(['q' => '   ']);
		$request->attributes->set('_api_user', $this->makeUser());

		$response = $this->controller->search($request);

		$this->assertSame(400, $response->getStatusCode());
	}

	#[Test]
	public function searchReturns200WithDataAndMetaEnvelope(): void
	{
		$request = new Request(['q' => 'phpbb']);
		$request->attributes->set('_api_user', $this->makeUser());

		$this->searchService->method('search')->willReturn(new PaginatedResult(
			items:   [$this->makeSearchResult()],
			total:   1,
			page:    1,
			perPage: 25,
		));

		$response = $this->controller->search($request);

		$this->assertSame(200, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		$this->assertArrayHasKey('data', $body);
		$this->assertArrayHasKey('meta', $body);
		$this->assertCount(1, $body['data']);
		$this->assertSame(1, $body['meta']['total']);
		$this->assertSame(1, $body['meta']['page']);
		$this->assertSame(25, $body['meta']['perPage']);
		$this->assertSame(1, $body['meta']['lastPage']);
	}

	#[Test]
	public function searchResultItemContainsExpectedFields(): void
	{
		$request = new Request(['q' => 'phpbb']);
		$request->attributes->set('_api_user', $this->makeUser());

		$this->searchService->method('search')->willReturn(new PaginatedResult(
			items:   [$this->makeSearchResult()],
			total:   1,
			page:    1,
			perPage: 25,
		));

		$response = $this->controller->search($request);
		$body     = json_decode($response->getContent(), true);
		$item     = $body['data'][0];

		$this->assertSame(42, $item['postId']);
		$this->assertSame(10, $item['topicId']);
		$this->assertSame(2, $item['forumId']);
		$this->assertSame('Test post subject', $item['subject']);
		$this->assertSame('Test forum name', $item['forumName']);
	}

	#[Test]
	public function searchPassesFiltersToService(): void
	{
		$request = new Request(['q' => 'test', 'forum_id' => '3', 'topic_id' => '7', 'user_id' => '5']);
		$request->attributes->set('_api_user', $this->makeUser());

		$this->searchService->expects($this->once())
			->method('search')
			->with(
				$this->callback(fn (SearchQuery $q) => $q->keywords === 'test' && $q->forumId === 3 && $q->topicId === 7 && $q->userId === 5),
				$this->anything(),
			)
			->willReturn($this->makeEmptyResult());

		$response = $this->controller->search($request);

		$this->assertSame(200, $response->getStatusCode());
	}

	#[Test]
	public function searchClampsPerPageTo50(): void
	{
		$request = new Request(['q' => 'test', 'perPage' => '200']);
		$request->attributes->set('_api_user', $this->makeUser());

		$this->searchService->expects($this->once())
			->method('search')
			->with(
				$this->callback(fn (SearchQuery $q) => $q->keywords === 'test'),
				$this->callback(fn (PaginationContext $ctx) => $ctx->perPage === 50),
			)
			->willReturn($this->makeEmptyResult(perPage: 50));

		$response = $this->controller->search($request);

		$this->assertSame(200, $response->getStatusCode());
	}
}

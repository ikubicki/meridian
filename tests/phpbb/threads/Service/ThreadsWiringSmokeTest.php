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

namespace phpbb\Tests\threads\Service;

use Doctrine\DBAL\Connection;
use phpbb\content\Pipeline\NullPostContentPipeline;
use phpbb\search\Contract\SearchIndexerInterface;
use phpbb\threads\Contract\PostRepositoryInterface;
use phpbb\threads\Contract\ThreadsServiceInterface;
use phpbb\threads\Contract\TopicRepositoryInterface;
use phpbb\threads\Repository\DbalPostRepository;
use phpbb\threads\Repository\DbalTopicRepository;
use phpbb\threads\ThreadsService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ThreadsWiringSmokeTest extends TestCase
{
	#[Test]
	public function threadsServiceImplementsThreadsServiceInterface(): void
	{
		$topicRepository = $this->createMock(TopicRepositoryInterface::class);
		$postRepository = $this->createMock(PostRepositoryInterface::class);
		$connection = $this->createMock(Connection::class);
		$searchIndexer = $this->createMock(SearchIndexerInterface::class);

		$service = new ThreadsService($topicRepository, $postRepository, $connection, $searchIndexer, new NullPostContentPipeline());

		self::assertInstanceOf(ThreadsServiceInterface::class, $service);
	}

	#[Test]
	public function repositoriesImplementRepositoryInterfaces(): void
	{
		$connection = $this->createMock(Connection::class);

		$topicRepository = new DbalTopicRepository($connection);
		$postRepository = new DbalPostRepository($connection);

		self::assertInstanceOf(TopicRepositoryInterface::class, $topicRepository);
		self::assertInstanceOf(PostRepositoryInterface::class, $postRepository);
	}
}

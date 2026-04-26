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

namespace phpbb\Tests\content\Service;

use phpbb\content\Contract\MetadataPluginInterface;
use phpbb\content\DTO\ContentContext;
use phpbb\content\Service\MetadataService;
use phpbb\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;

class MetadataServiceTest extends IntegrationTestCase
{
	private MetadataService $service;

	protected function setUpSchema(): void
	{
		$this->connection->executeStatement(
			'CREATE TABLE phpbb_posts (
				post_id  INTEGER PRIMARY KEY AUTOINCREMENT,
				post_text TEXT NOT NULL DEFAULT "",
				metadata  TEXT NULL
			)',
		);

		$this->connection->executeStatement(
			"INSERT INTO phpbb_posts (post_id, post_text) VALUES (1, 'hello')",
		);

		$this->service = new MetadataService(plugins: [], connection: $this->connection);
	}

	#[Test]
	public function collectForPostMergesPluginResults(): void
	{
		$pluginA = $this->createMock(MetadataPluginInterface::class);
		$pluginA->method('extractMetadata')->willReturn(['key_a' => 'val_a']);

		$pluginB = $this->createMock(MetadataPluginInterface::class);
		$pluginB->method('extractMetadata')->willReturn(['key_b' => 'val_b']);

		$service = new MetadataService(plugins: [$pluginA, $pluginB], connection: $this->connection);
		$ctx     = new ContentContext(actorId: 1, forumId: 2, topicId: 3);

		$result = $service->collectForPost('content', $ctx);

		$this->assertSame(['key_a' => 'val_a', 'key_b' => 'val_b'], $result);
	}

	#[Test]
	public function collectForPostWithNoPluginsReturnsEmptyArray(): void
	{
		$ctx    = new ContentContext(actorId: 1, forumId: 2, topicId: 3);
		$result = $this->service->collectForPost('content', $ctx);

		$this->assertSame([], $result);
	}

	#[Test]
	public function saveForPostWritesJsonToDatabase(): void
	{
		$this->service->saveForPost(1, ['foo' => 'bar', 'count' => 3]);

		$row = $this->connection->fetchAssociative('SELECT metadata FROM phpbb_posts WHERE post_id = 1');
		$this->assertNotFalse($row);
		$this->assertSame(['foo' => 'bar', 'count' => 3], json_decode($row['metadata'], true));
	}

	#[Test]
	public function saveForPostSkipsWriteWhenMetadataEmpty(): void
	{
		$this->service->saveForPost(1, []);

		$row = $this->connection->fetchAssociative('SELECT metadata FROM phpbb_posts WHERE post_id = 1');
		$this->assertNotFalse($row);
		$this->assertNull($row['metadata']);
	}

	#[Test]
	public function saveForPostThrowsForUnknownPost(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Post 999 not found when saving metadata');

		$this->service->saveForPost(999, ['key' => 'val']);
	}
}

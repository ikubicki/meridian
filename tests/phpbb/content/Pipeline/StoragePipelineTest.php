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

namespace phpbb\Tests\content\Pipeline;

use phpbb\content\ContentStage;
use phpbb\content\Contract\StoragePluginInterface;
use phpbb\content\DTO\ContentContext;
use phpbb\content\Pipeline\StoragePipeline;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StoragePipelineTest extends TestCase
{
	private ContentContext $ctx;

	protected function setUp(): void
	{
		$this->ctx = new ContentContext(actorId: 1, forumId: 2, topicId: 3);
	}

	private function makePlugin(ContentStage $stage): StoragePluginInterface&MockObject
	{
		$plugin = $this->createMock(StoragePluginInterface::class);
		$plugin->method('supportsStage')->willReturnCallback(static fn (ContentStage $s) => $s === $stage);
		$plugin->method('process')->willReturnArgument(0);

		return $plugin;
	}

	#[Test]
	public function processPostSaveCallsOnlyPostSavePlugins(): void
	{
		$postSave = $this->makePlugin(ContentStage::POST_SAVE);
		$preSave  = $this->makePlugin(ContentStage::PRE_SAVE);

		$postSave->expects($this->once())->method('process');
		$preSave->expects($this->never())->method('process');

		$pipeline = new StoragePipeline([$postSave, $preSave]);
		$pipeline->processPostSave(42, 'content', $this->ctx);
	}

	#[Test]
	public function processPostSaveWithNoPluginsDoesNotThrow(): void
	{
		$pipeline = new StoragePipeline([]);
		$pipeline->processPostSave(42, 'content', $this->ctx);

		$this->addToAssertionCount(1);
	}

	#[Test]
	public function pluginNotSupportingPostSaveIsSkipped(): void
	{
		$plugin = $this->createMock(StoragePluginInterface::class);
		$plugin->method('supportsStage')->willReturn(false);
		$plugin->expects($this->never())->method('process');

		$pipeline = new StoragePipeline([$plugin]);
		$pipeline->processPostSave(1, 'text', $this->ctx);
	}
}

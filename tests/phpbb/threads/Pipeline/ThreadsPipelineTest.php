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

namespace phpbb\Tests\threads\Pipeline;

use phpbb\content\ContentStage;
use phpbb\content\DTO\ContentContext;
use phpbb\threads\Contract\ThreadsPluginInterface;
use phpbb\threads\Pipeline\ThreadsPipeline;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ThreadsPipelineTest extends TestCase
{
	private ContentContext $ctx;

	protected function setUp(): void
	{
		$this->ctx = new ContentContext(actorId: 1, forumId: 2, topicId: 3);
	}

	private function makePlugin(ContentStage $stage, string $suffix): ThreadsPluginInterface&MockObject
	{
		$plugin = $this->createMock(ThreadsPluginInterface::class);
		$plugin->method('supportsStage')->willReturnCallback(static fn (ContentStage $s) => $s === $stage);
		$plugin->method('process')->willReturnCallback(static fn (string $c) => $c . $suffix);

		return $plugin;
	}

	#[Test]
	public function processForSaveCallsOnlyPreSavePlugins(): void
	{
		$preSave    = $this->makePlugin(ContentStage::PRE_SAVE, '[pre]');
		$preOutput  = $this->makePlugin(ContentStage::PRE_OUTPUT, '[out]');
		$pipeline   = new ThreadsPipeline([$preSave, $preOutput]);

		$result = $pipeline->processForSave('hello', $this->ctx);

		$this->assertSame('hello[pre]', $result);
	}

	#[Test]
	public function processForOutputCallsOnlyPreOutputPlugins(): void
	{
		$preSave   = $this->makePlugin(ContentStage::PRE_SAVE, '[pre]');
		$preOutput = $this->makePlugin(ContentStage::PRE_OUTPUT, '[out]');
		$pipeline  = new ThreadsPipeline([$preSave, $preOutput]);

		$result = $pipeline->processForOutput('hello', $this->ctx);

		$this->assertSame('hello[out]', $result);
	}

	#[Test]
	public function processForSaveWithNoPluginsReturnsOriginalContent(): void
	{
		$pipeline = new ThreadsPipeline([]);

		$result = $pipeline->processForSave('original', $this->ctx);

		$this->assertSame('original', $result);
	}

	#[Test]
	public function processForOutputWithNoPluginsReturnsOriginalContent(): void
	{
		$pipeline = new ThreadsPipeline([]);

		$result = $pipeline->processForOutput('original', $this->ctx);

		$this->assertSame('original', $result);
	}

	#[Test]
	public function pluginsAreAppliedInOrder(): void
	{
		$first  = $this->makePlugin(ContentStage::PRE_SAVE, '_first');
		$second = $this->makePlugin(ContentStage::PRE_SAVE, '_second');
		$pipeline = new ThreadsPipeline([$first, $second]);

		$result = $pipeline->processForSave('text', $this->ctx);

		$this->assertSame('text_first_second', $result);
	}

	#[Test]
	public function pluginNotSupportingStageIsSkipped(): void
	{
		$plugin = $this->createMock(ThreadsPluginInterface::class);
		$plugin->method('supportsStage')->willReturn(false);
		$plugin->expects($this->never())->method('process');

		$pipeline = new ThreadsPipeline([$plugin]);
		$pipeline->processForSave('text', $this->ctx);
	}

	#[Test]
	public function contextIsPassedToPlugin(): void
	{
		$plugin = $this->createMock(ThreadsPluginInterface::class);
		$plugin->method('supportsStage')->willReturn(true);
		$plugin->expects($this->once())
			->method('process')
			->with('text', $this->ctx)
			->willReturn('text');

		$pipeline = new ThreadsPipeline([$plugin]);
		$pipeline->processForSave('text', $this->ctx);
	}
}

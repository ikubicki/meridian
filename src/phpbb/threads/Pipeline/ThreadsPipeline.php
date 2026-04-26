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

namespace phpbb\threads\Pipeline;

use phpbb\content\ContentStage;
use phpbb\content\DTO\ContentContext;
use phpbb\threads\Contract\ThreadsPipelineInterface;
use phpbb\threads\Contract\ThreadsPluginInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class ThreadsPipeline implements ThreadsPipelineInterface
{
	/**
	 * @param iterable<ThreadsPluginInterface> $plugins
	 */
	public function __construct(
		#[AutowireIterator('phpbb.threads_plugin')] private readonly iterable $plugins,
	) {
	}

	public function processForSave(string $content, ContentContext $context): string
	{
		foreach ($this->plugins as $plugin) {
			if ($plugin->supportsStage(ContentStage::PRE_SAVE)) {
				$content = $plugin->process($content, $context);
			}
		}

		return $content;
	}

	public function processForOutput(string $content, ContentContext $context): string
	{
		foreach ($this->plugins as $plugin) {
			if ($plugin->supportsStage(ContentStage::PRE_OUTPUT)) {
				$content = $plugin->process($content, $context);
			}
		}

		return $content;
	}
}

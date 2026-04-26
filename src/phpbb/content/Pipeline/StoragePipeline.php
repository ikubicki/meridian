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

namespace phpbb\content\Pipeline;

use phpbb\content\ContentStage;
use phpbb\content\Contract\StoragePipelineInterface;
use phpbb\content\Contract\StoragePluginInterface;
use phpbb\content\DTO\ContentContext;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class StoragePipeline implements StoragePipelineInterface
{
	/**
	 * @param iterable<StoragePluginInterface> $plugins
	 */
	public function __construct(
		#[AutowireIterator('phpbb.storage_plugin')] private readonly iterable $plugins,
	) {
	}

	public function processPostSave(int $postId, string $content, ContentContext $context): void
	{
		foreach ($this->plugins as $plugin) {
			if ($plugin->supportsStage(ContentStage::POST_SAVE)) {
				$plugin->process($content, $context);
			}
		}
	}
}

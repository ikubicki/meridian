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

use phpbb\content\Contract\PostContentPipelineInterface;
use phpbb\content\DTO\ContentContext;

/**
 * No-op pipeline used in tests and environments with no plugins registered.
 */
final class NullPostContentPipeline implements PostContentPipelineInterface
{
	public function processForSave(string $content, ContentContext $context): string
	{
		return $content;
	}

	public function processForOutput(string $content, ContentContext $context): string
	{
		return $content;
	}
}

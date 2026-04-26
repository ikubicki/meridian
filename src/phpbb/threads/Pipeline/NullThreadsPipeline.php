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

use phpbb\content\DTO\ContentContext;
use phpbb\threads\Contract\ThreadsPipelineInterface;

/**
 * No-op pipeline used in tests and environments with no plugins registered.
 */
final class NullThreadsPipeline implements ThreadsPipelineInterface
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

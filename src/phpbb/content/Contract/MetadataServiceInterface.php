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

namespace phpbb\content\Contract;

use phpbb\content\DTO\ContentContext;

interface MetadataServiceInterface
{
	/**
	 * @return array<string, mixed>
	 */
	public function collectForPost(string $content, ContentContext $context): array;

	/**
	 * @param array<string, mixed> $metadata
	 */
	public function saveForPost(int $postId, array $metadata): void;
}

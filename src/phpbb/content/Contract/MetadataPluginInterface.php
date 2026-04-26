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
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('phpbb.metadata_plugin')]
interface MetadataPluginInterface
{
	public function getName(): string;

	/**
	 * @return array<string, mixed>
	 */
	public function extractMetadata(string $content, ContentContext $context): array;
}

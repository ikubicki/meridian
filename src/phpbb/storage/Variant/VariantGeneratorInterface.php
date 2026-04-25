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

namespace phpbb\storage\Variant;

interface VariantGeneratorInterface
{
	/**
	 * Generate a variant from raw file content.
	 *
	 * @return string Raw bytes of the generated variant
	 * @throws \RuntimeException on generation failure
	 */
	public function generate(string $content): string;
}

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

final class ThumbnailGenerator implements VariantGeneratorInterface
{
	private const MAX_WIDTH  = 200;
	private const MAX_HEIGHT = 200;

	public function generate(string $content): string
	{
		$source = @imagecreatefromstring($content);
		if ($source === false) {
			throw new \RuntimeException('Cannot create image from file content');
		}

		$srcW = imagesx($source);
		$srcH = imagesy($source);

		[$dstW, $dstH] = $this->calculateDimensions($srcW, $srcH);

		$thumb = imagecreatetruecolor($dstW, $dstH);
		if ($thumb === false) {
			imagedestroy($source);

			throw new \RuntimeException('Cannot create thumbnail canvas');
		}

		imagecopyresampled($thumb, $source, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
		imagedestroy($source);

		ob_start();
		imagejpeg($thumb, null, 85);
		imagedestroy($thumb);

		return ob_get_clean();
	}

	private function calculateDimensions(int $srcW, int $srcH): array
	{
		if ($srcW <= self::MAX_WIDTH && $srcH <= self::MAX_HEIGHT) {
			return [$srcW, $srcH];
		}

		$ratio = min(self::MAX_WIDTH / $srcW, self::MAX_HEIGHT / $srcH);

		return [(int) round($srcW * $ratio), (int) round($srcH * $ratio)];
	}
}

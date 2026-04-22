<?php

/**
 *
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

declare(strict_types=1);

namespace phpbb;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
	use MicroKernelTrait;

	public function getProjectDir(): string
	{
		return dirname(__DIR__, 2);
	}

	public function getConfigDir(): string
	{
		return __DIR__ . '/config';
	}

	public function getCacheDir(): string
	{
		return $this->getProjectDir() . '/cache/phpbb4/' . $this->environment;
	}

	public function getLogDir(): string
	{
		return $this->getProjectDir() . '/var/log/phpbb4';
	}
}

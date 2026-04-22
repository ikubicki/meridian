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

namespace phpbb\user\Exception;

/**
 * Thrown when an operation is attempted by or for a banned actor.
 */
class BannedException extends \RuntimeException
{
	public function __construct(string $message = 'Banned.', int $code = 0, ?\Throwable $previous = null)
	{
		parent::__construct($message, $code, $previous);
	}
}

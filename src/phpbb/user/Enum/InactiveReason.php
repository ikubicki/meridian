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

namespace phpbb\user\Enum;

enum InactiveReason: int
{
	/** Awaiting registration e-mail confirmation. */
	case Register = 1;

	/** Profile change triggered re-activation e-mail. */
	case Profile = 2;

	/** Administrator manually deactivated the account. */
	case Manual = 3;

	/** Board is awaiting a remind-me confirmation. */
	case Remind = 4;
}

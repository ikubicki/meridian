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

enum DeleteMode: string
{
	/** Anonymise PII and reassign content to the anonymous user. */
	case Retain = 'retain';

	/** Hard-delete user and all owned content via cascading events. */
	case Remove = 'remove';

	/** Deactivate account and anonymise PII; content remains attributed. */
	case Soft = 'soft';
}

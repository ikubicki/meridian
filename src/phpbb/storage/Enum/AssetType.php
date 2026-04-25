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

namespace phpbb\storage\Enum;

enum AssetType: string
{
	case Attachment = 'attachment';
	case Avatar     = 'avatar';
	case Export     = 'export';

	public function toVisibility(): FileVisibility
	{
		return match ($this) {
			self::Avatar     => FileVisibility::Public,
			self::Attachment => FileVisibility::Private,
			self::Export     => FileVisibility::Private,
		};
	}

	public function storagePath(): string
	{
		return match ($this) {
			self::Avatar     => 'images/avatars/upload/',
			self::Attachment => 'files/',
			self::Export     => 'files/',
		};
	}
}

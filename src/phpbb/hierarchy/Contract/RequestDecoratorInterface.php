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

namespace phpbb\hierarchy\Contract;

use phpbb\hierarchy\DTO\CreateForumRequest;
use phpbb\hierarchy\DTO\UpdateForumRequest;

interface RequestDecoratorInterface
{
	public function decorateCreate(CreateForumRequest $request): CreateForumRequest;

	public function decorateUpdate(UpdateForumRequest $request): UpdateForumRequest;
}

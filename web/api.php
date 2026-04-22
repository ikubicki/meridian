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

use phpbb\Kernel;
use Symfony\Component\HttpFoundation\Request;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$kernel   = new Kernel($_SERVER['PHPBB_APP_ENV'] ?? 'production', (bool) ($_SERVER['PHPBB_APP_DEBUG'] ?? false));
$request  = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);

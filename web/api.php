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

/**
 * Forum REST API entry point.
 *
 * No session_begin(), no acl() — authentication is handled exclusively
 * by api.auth_subscriber (Phase 1: returns 501 stub).
 */

define('PHPBB_FILESYSTEM_ROOT', __DIR__ . '/../');
$phpbb_root_path = './';
include(PHPBB_FILESYSTEM_ROOT . 'src/phpbb/common/common.php');

/** @var \phpbb\core\Application $app */
$phpbb_container->get('api.application')->run();

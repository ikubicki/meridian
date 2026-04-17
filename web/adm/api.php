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
 * Admin REST API entry point.
 *
 * No session_begin(), no acl() in this script — authentication and admin
 * permission checks are deferred to event subscribers (Phase 2).
 */

define('ADMIN_START', true);
define('NEED_SID', true);
define('IN_ADMIN', true);

$phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : '../../';
require($phpbb_root_path . 'src/phpbb/common/common.php');

/** @var \phpbb\core\Application $app */
$phpbb_container->get('admin_api.application')->run();

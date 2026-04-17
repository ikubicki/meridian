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
 * Installer REST API entry point.
 *
 * Uses the isolated installer DI container — common.php is NEVER included
 * (it would trigger checkInstallation() and redirect if phpBB is not installed).
 */

define('IN_INSTALL', true);
define('PHPBB_ROOT_PATH', __DIR__ . '/../../');

// startup.php uses $phpbb_root_path as a variable (not the constant)
$phpbb_root_path = PHPBB_ROOT_PATH;

require($phpbb_root_path . 'src/phpbb/install/startup.php');

/** @var \phpbb\core\Application $app */
$phpbb_installer_container->get('install_api.application')->run();

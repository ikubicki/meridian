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

namespace phpbb\install;

/**
* Checks if phpBB is installed and redirects to the installer if not.
*
* @param string $phpbb_root_path Path to the phpBB root
*/
function checkInstallation(string $phpbb_root_path): void
{
	if (!defined('PHPBB_INSTALLED'))
	{
		redirectToInstaller($phpbb_root_path);
	}
}

/**
* Redirects the user to the phpBB installer and exits.
*
* @param string $phpbb_root_path Path to the phpBB root
*/
function redirectToInstaller(string $phpbb_root_path): never
{
	require(__DIR__ . '/../common/functions.php');

	// We have to generate a full HTTP/1.1 header here since we can't guarantee to have any of the information
	// available as used by the redirect function
	$server_name = (!empty($_SERVER['HTTP_HOST'])) ? strtolower($_SERVER['HTTP_HOST']) : ((!empty($_SERVER['SERVER_NAME'])) ? $_SERVER['SERVER_NAME'] : getenv('SERVER_NAME'));
	$server_port = (!empty($_SERVER['SERVER_PORT'])) ? (int) $_SERVER['SERVER_PORT'] : (int) getenv('SERVER_PORT');
	$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 1 : 0;

	if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
	{
		$secure = 1;
		$server_port = 443;
	}

	$script_path = phpbb_get_install_redirect($phpbb_root_path);

	// Eliminate . and .. from the path
	require(__DIR__ . '/../forums/filesystem/filesystem.php');
	$phpbb_filesystem = new \phpbb\filesystem\filesystem();
	$script_path = $phpbb_filesystem->clean_path($script_path);

	$url = (($secure) ? 'https://' : 'http://') . $server_name;

	if ($server_port && (($secure && $server_port <> 443) || (!$secure && $server_port <> 80)))
	{
		// HTTP HOST can carry a port number...
		if (strpos($server_name, ':') === false)
		{
			$url .= ':' . $server_port;
		}
	}

	$url .= $script_path;
	header('Location: ' . $url);
	exit;
}

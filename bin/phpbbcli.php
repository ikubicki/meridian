#!/usr/bin/env php
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

use Symfony\Component\Console\Input\ArgvInput;

if (php_sapi_name() != 'cli')
{
	echo 'This program must be run from the command line.' . PHP_EOL;
	exit(1);
}

define('IN_PHPBB', true);

$phpbb_root_path = __DIR__ . '/../';
require($phpbb_root_path . 'src/phpbb/common/startup.php');

$phpbb_config_php_file = new \phpbb\config_php_file($phpbb_root_path);
extract($phpbb_config_php_file->get_all());

if (!defined('PHPBB_ENVIRONMENT'))
{
	@define('PHPBB_ENVIRONMENT', 'production');
}

require($phpbb_root_path . 'src/phpbb/common/constants.php');
require($phpbb_root_path . 'src/phpbb/common/functions.php');
require($phpbb_root_path . 'src/phpbb/common/functions_admin.php');
require($phpbb_root_path . 'src/phpbb/common/utf/utf_tools.php');
require($phpbb_root_path . 'src/phpbb/common/functions_compatibility.php');

$phpbb_container_builder = new \phpbb\di\container_builder($phpbb_root_path);
$phpbb_container = $phpbb_container_builder->with_config($phpbb_config_php_file);

$input = new ArgvInput();

if ($input->hasParameterOption(array('--env')))
{
	$phpbb_container_builder->with_environment($input->getParameterOption('--env'));
}

if ($input->hasParameterOption(array('--safe-mode')))
{
	$phpbb_container_builder->without_extensions();
	$phpbb_container_builder->without_cache();
}
else
{
	$phpbb_class_loader_ext = new \phpbb\class_loader('\\', "{$phpbb_root_path}ext/");
	$phpbb_class_loader_ext->register();
}

$phpbb_container = $phpbb_container_builder->get_container();
$phpbb_container->get('request')->enable_super_globals();
require($phpbb_root_path . 'src/phpbb/common/compatibility_globals.php');

register_compatibility_globals();

/** @var \phpbb\config\config $config */
$config = $phpbb_container->get('config');

/** @var \phpbb\language\language $language */
$language = $phpbb_container->get('language');
$language->set_default_language($config['default_lang']);
$language->add_lang(array('common', 'acp/common', 'cli'));

/* @var $user \phpbb\user */
$user = $phpbb_container->get('user');
$user->data['user_id'] = ANONYMOUS;
$user->ip = '127.0.0.1';

$application = new \phpbb\console\application('phpBB Console', PHPBB_VERSION, $language, $config);
$application->setDispatcher($phpbb_container->get('dispatcher'));
$application->register_container_commands($phpbb_container->get('console.command_collection'));
$application->run($input);

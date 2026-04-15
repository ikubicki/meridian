<?php
// phpBB configuration — loaded from environment variables
// Set the following env vars (e.g. in docker-compose.yml or .env)

$dbms     = getenv('PHPBB_DB_DRIVER') ?: 'phpbb\db\driver\mysqli';
$dbhost   = getenv('PHPBB_DB_HOST')   ?: 'db';
$dbport   = getenv('PHPBB_DB_PORT')   ?: '';
$dbname   = getenv('PHPBB_DB_NAME')   ?: 'phpbb';
$dbuser   = getenv('PHPBB_DB_USER')   ?: 'phpbb';
$dbpasswd = getenv('PHPBB_DB_PASSWD') ?: '';
$table_prefix = getenv('PHPBB_DB_PREFIX') ?: 'phpbb_';

$phpbb_adm_relative_path = 'web/adm/';
$acm_type = getenv('PHPBB_CACHE_DRIVER') ?: 'phpbb\cache\driver\file';

$phpbb_environment = getenv('PHPBB_ENVIRONMENT') ?: 'production';

if (getenv('PHPBB_INSTALLED') === 'true')
{
	@define('PHPBB_INSTALLED', true);
}

@define('PHPBB_ENVIRONMENT', $phpbb_environment);
// @define('DEBUG_CONTAINER', true);

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

/**
 * Emit a structured JSON log entry to stderr (→ docker logs).
 */
function phpbb_log(string $level, string $message, array $context = []): void
{
	$entry = array_filter([
		'time'    => date('c'),
		'level'   => $level,
		'message' => $message,
	] + $context);

	$line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

	// Write directly to fd 2 to avoid FPM's "NOTICE: PHP message:" prefix
	// that error_log() would add via catch_workers_output.
	$fd = @fopen('php://stderr', 'w');
	if ($fd !== false) {
		fwrite($fd, $line);
		fclose($fd);
		return;
	}

	// Fallback — error_log adds an FPM prefix but we still want the entry captured.
	error_log($line);
}

set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	if (!(error_reporting() & $errno)) {
		return false;
	}
	phpbb_log('error', $errstr, [
		'errno' => $errno,
		'file'  => $errfile,
		'line'  => $errline,
	]);
	return true;
});

set_exception_handler(static function (\Throwable $e): void {
	phpbb_log('critical', $e->getMessage(), [
		'exception' => get_class($e),
		'file'      => $e->getFile(),
		'line'      => $e->getLine(),
		'trace'     => $e->getTraceAsString(),
	]);
});

$kernel   = new Kernel($_SERVER['PHPBB_APP_ENV'] ?? 'production', (bool) ($_SERVER['PHPBB_APP_DEBUG'] ?? false));
$request  = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);

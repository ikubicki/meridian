<?php
/**
 * CSS file provider with style fallback to prosilver.
 *
 * Usage: /css.php?style=STYLE_NAME&file=FILE_PATH
 *
 * Serves files from src/phpbb/styles/{style}/theme/{file}
 * with fallback to src/phpbb/styles/prosilver/theme/{file}
 */

define('IN_PHPBB', true);

$phpbb_root_path = __DIR__ . '/../';
$styles_root = $phpbb_root_path . 'src/phpbb/styles/';
$adm_style_root = __DIR__ . '/adm/style/';
$fallback_style = 'prosilver';

// --- Input validation ---

$style = isset($_GET['style']) ? $_GET['style'] : $fallback_style;
$file  = isset($_GET['file'])  ? $_GET['file']  : '';

// Style name: only alphanumeric, dash, underscore
if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $style))
{
	http_response_code(400);
	exit;
}

// File path: no traversal, no leading slash, only safe characters
if (!preg_match('/^[a-zA-Z0-9_\-\/\.]+$/', $file) || strpos($file, '..') !== false || $file[0] === '/')
{
	http_response_code(400);
	exit;
}

// Only allow CSS files
if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'css')
{
	http_response_code(403);
	exit;
}

// --- File resolution with fallback ---

function resolve_css_file($styles_root, $style, $file)
{
	$path = realpath($styles_root . $style . '/theme/' . $file);
	$expected_prefix = realpath($styles_root);

	if ($path !== false && $expected_prefix !== false && strpos($path, $expected_prefix) === 0 && is_file($path))
	{
		return $path;
	}

	return false;
}

// Special case: adm style served from web/adm/style/
if ($style === 'adm')
{
	$path = realpath($adm_style_root . $file);
	$expected_prefix = realpath($adm_style_root);

	if ($path !== false && $expected_prefix !== false && strpos($path, $expected_prefix) === 0 && is_file($path))
	{
		$resolved = $path;
	}
	else
	{
		http_response_code(404);
		exit;
	}
}
else
{
	$resolved = resolve_css_file($styles_root, $style, $file);

	if ($resolved === false && $style !== $fallback_style)
	{
		$resolved = resolve_css_file($styles_root, $fallback_style, $file);
	}

	if ($resolved === false)
	{
		http_response_code(404);
		exit;
	}
}

// --- Serve the file ---

$mtime = filemtime($resolved);
$etag  = '"' . md5($resolved . $mtime) . '"';

header('Content-Type: text/css; charset=UTF-8');
header('Cache-Control: public, max-age=86400');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

// 304 Not Modified support
if (
	(isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) ||
	(isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $mtime)
)
{
	http_response_code(304);
	exit;
}

readfile($resolved);

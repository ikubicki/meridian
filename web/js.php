<?php
/**
 * JavaScript file provider.
 *
 * Usage: /js.php?file=PATH
 *
 * PATH is relative to the web/ directory.
 * Only .js files inside web/ are served.
 * Supports ETag / 304 Not Modified.
 */


$web_root = __DIR__;

// --- Input validation ---

$file = isset($_GET['file']) ? $_GET['file'] : '';

// Safe characters only, no traversal, no leading slash
if (
	!preg_match('/^[a-zA-Z0-9_\-\/\.]+$/', $file) ||
	strpos($file, '..') !== false ||
	$file === '' ||
	$file[0] === '/'
)
{
	http_response_code(400);
	exit;
}

// Only allow .js files
if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'js')
{
	http_response_code(403);
	exit;
}

// --- Resolve and validate path ---

$resolved        = realpath($web_root . '/' . $file);
$expected_prefix = realpath($web_root);

if ($resolved === false || $expected_prefix === false || strpos($resolved, $expected_prefix) !== 0 || !is_file($resolved))
{
	http_response_code(404);
	exit;
}

// --- Serve the file ---

$mtime = filemtime($resolved);
$etag  = '"' . md5($resolved . $mtime) . '"';

header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: public, max-age=86400');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

if (
	(isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) ||
	(isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $mtime)
)
{
	http_response_code(304);
	exit;
}

readfile($resolved);

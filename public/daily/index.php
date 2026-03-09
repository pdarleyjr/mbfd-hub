<?php
/**
 * SPA Fallback for PHP Built-in Dev Server
 * 
 * The PHP built-in server routes requests to public/daily/ through this file
 * when the requested sub-path doesn't match a real file. This serves the
 * React SPA's index.html for all client-side routes.
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');

// Strip /daily prefix to get the relative path within this directory
$relativePath = preg_replace('#^/daily#', '', $uri);
$localFile = __DIR__ . $relativePath;

// If it's a real file (JS, CSS, images, etc.), let the server handle it
if ($relativePath && $relativePath !== '/' && is_file($localFile)) {
    return false;
}

// For everything else, serve the SPA's index.html
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
readfile(__DIR__ . '/index.html');

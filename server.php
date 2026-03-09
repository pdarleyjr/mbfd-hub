<?php

/**
 * Laravel - A PHP Framework For Web Artisans
 *
 * Custom server.php that handles SPA directories properly.
 * The PHP built-in dev server returns 404 for paths under real directories
 * (like public/daily/) when the sub-path doesn't match a real file.
 * This script intercepts those requests and routes them to Laravel.
 */

$publicPath = getcwd();

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

// SPA catch-all: serve daily/index.html for any /daily/* path that isn't a real file
if (preg_match('#^/daily(/|$)#', $uri)) {
    $filePath = $publicPath . $uri;
    // If it's a real file (JS, CSS, images), serve it directly
    if (is_file($filePath)) {
        return false;
    }
    // Otherwise serve the SPA's index.html
    $spaIndex = $publicPath . '/daily/index.html';
    if (is_file($spaIndex)) {
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        readfile($spaIndex);
        return;
    }
}

// Standard Laravel server.php behavior
if ($uri !== '/' && file_exists($publicPath.$uri)) {
    return false;
}

require_once $publicPath.'/index.php';

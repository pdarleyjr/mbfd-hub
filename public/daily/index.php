<?php

/**
 * SPA Fallback Router for PHP Built-in Dev Server
 * 
 * The PHP built-in dev server checks if a file exists in the public directory
 * before passing to the router script. Since public/daily/ is a real directory,
 * sub-path requests like /daily/vehicle-inspections/e4 get routed here instead
 * of to Laravel's router. This file forwards those requests to Laravel.
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');
$publicPath = dirname(__DIR__);

// If the request is for a real file in public/daily/ (like assets, icons, etc.), serve it
$localFile = __DIR__ . str_replace('/daily', '', $uri);
if ($uri !== '/daily' && $uri !== '/daily/' && is_file($localFile)) {
    return false;
}

// Otherwise, serve the SPA's index.html
$indexPath = __DIR__ . '/index.html';
if (is_file($indexPath)) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Content-Type: text/html; charset=UTF-8');
    readfile($indexPath);
    exit;
}

// Final fallback: route through Laravel
chdir($publicPath);
require $publicPath . '/index.php';

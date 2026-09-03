<?php

/**
 * Router for the PHP built-in server used by the production container.
 *
 * Only real public files bypass Laravel. SPA navigation must reach Laravel so
 * route middleware, including canonical authentication, is always enforced.
 */
$publicPath = getcwd();

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

if ($uri !== '/' && is_file($publicPath.$uri)) {
    return false;
}

// The built-in server changes these values for nested paths below a real
// directory. Normalize them so Symfony resolves /daily/* from the site root.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require_once $publicPath.'/index.php';

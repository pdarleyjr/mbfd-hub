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
    // Content-hashed Vite output never changes under its name. The built-in
    // server sends no Cache-Control for files it serves itself, so the edge
    // fell back to a four-hour TTL; serve these with a long immutable lifetime.
    $immutableTypes = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'text/javascript; charset=UTF-8',
        'mjs' => 'text/javascript; charset=UTF-8',
        'woff2' => 'font/woff2',
        'mp3' => 'audio/mpeg',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
    ];
    if (preg_match('#^/(?:build|daily)/assets/[A-Za-z0-9._-]+-[A-Za-z0-9_-]{8,}\.([a-z0-9]+)$#', $uri, $asset) === 1
        && isset($immutableTypes[$asset[1]])) {
        header('Content-Type: '.$immutableTypes[$asset[1]]);
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Length: '.(string) filesize($publicPath.$uri));
        header('X-Content-Type-Options: nosniff');
        readfile($publicPath.$uri);

        return true;
    }

    // Service worker scripts are unhashed, so without a Cache-Control the edge
    // applied its four-hour default and kept serving a retired worker after a
    // release. Always revalidate every registered worker URL, including the
    // legacy ones older installs may still check for updates.
    $serviceWorkers = ['/sw.js', '/admin-pwa/service-worker.js', '/daily/sw.js', '/daily/service-worker.js'];
    if (in_array($uri, $serviceWorkers, true)) {
        header('Content-Type: text/javascript; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
        // This is a real file under public/, so it never reaches the Laravel route that adds the
        // scope header. A registration made from this URL has scope /admin/, which browsers only
        // accept (for the script AND for every later update check) when the script allows it.
        if ($uri === '/admin-pwa/service-worker.js') {
            header('Service-Worker-Allowed: /admin/');
        }
        header('Content-Length: '.(string) filesize($publicPath.$uri));
        header('X-Content-Type-Options: nosniff');
        readfile($publicPath.$uri);

        return true;
    }

    return false;
}

// The built-in server changes these values for nested paths below a real
// directory. Normalize them so Symfony resolves /daily/* from the site root.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require_once $publicPath.'/index.php';

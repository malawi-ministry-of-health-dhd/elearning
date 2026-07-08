<?php
// Router script for PHP's built-in server when running Moodle 5.2 locally.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$publicroot = __DIR__ . '/public';
$file = $publicroot . $path;

if (preg_match('#^/(vendor|node_modules)(/|$)#', $path) ||
    preg_match('#^/\.#', $path) ||
    preg_match('#/(composer\.json|composer\.lock|phpunit\.xml(\.dist)?|environment\.xml)$#i', $path) ||
    preg_match('#/db/install\.xml$#i', $path) ||
    preg_match('#/(readme[^/]*\.(txt|md)|upgrade\.txt|UPGRADING(\-CURRENT)?\.md)$#i', $path) ||
    preg_match('#/(fixtures|behat)/#', $path) ||
    $path === '/lib/classes/' ||
    str_starts_with($path, '/lib/classes/')
) {
    http_response_code(404);
    echo 'Not Found';
    return true;
}

if ($path === '/' || is_file($file) || (is_dir($file) && is_file($file . '/index.php'))) {
    return false;
}

if (preg_match('#^(.+\.php)(/.*)?$#', $path, $matches) && is_file($publicroot . $matches[1])) {
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = $publicroot . '/r.php';
require $publicroot . '/r.php';

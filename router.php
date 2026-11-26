<?php
// Dev/staging router for PHP's built-in server: php -S 127.0.0.1:8082 -t . router.php
// In production (nginx), use try_files $uri $uri/ /index.php?$query_string; and deny /app, /sql, /migrate.
$path = parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file) && !str_ends_with($path, 'index.php')) {
    return false; // serve the static file / installer script directly
}
if (is_dir($file) && is_file(rtrim($file, '/') . '/index.php')) {
    require rtrim($file, '/') . '/index.php';
    return true;
}
require __DIR__ . '/index.php';
return true;

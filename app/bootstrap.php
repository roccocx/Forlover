<?php
declare(strict_types=1);

define('FORLOVER', true);
define('ROOT', dirname(__DIR__));
define('VERSION', '2.0.1');
define('PROJECT_URL', 'https://github.com/roccocx/Forlover');

function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = ROOT . '/config.php';
        $cfg = is_file($file) ? (require $file) : [];
    }
    return $cfg;
}

function is_installed(): bool
{
    return is_file(ROOT . '/install.lock') && is_file(ROOT . '/config.php');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config()['db'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'] ?? 3306, $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

/** @return array<string,string> */
function settings(): array
{
    static $all = null;
    if ($all === null) {
        $all = [];
        foreach (db()->query('SELECT `k`,`v` FROM `settings`') as $row) {
            $all[$row['k']] = (string)($row['v'] ?? '');
        }
    }
    return $all;
}

function setting(string $key, string $default = ''): string
{
    $all = settings();
    return $all[$key] ?? $default;
}

function set_setting(string $key, string $value): void
{
    $st = db()->prepare('INSERT INTO `settings` (`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v`=VALUES(`v`)');
    $st->execute([$key, $value]);
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

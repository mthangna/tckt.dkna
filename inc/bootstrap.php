<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_NAME', 'Công cụ Phòng TCKT');

$configFile = APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Chưa có file config.php. Hãy sao chép config.sample.php thành config.php và điền thông tin CSDL, sau đó mở install.php.');
}
$GLOBALS['APP_CONFIG'] = require $configFile;
date_default_timezone_set($GLOBALS['APP_CONFIG']['timezone'] ?? 'Asia/Ho_Chi_Minh');
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

function config(string $key, $default = null)
{
    return $GLOBALS['APP_CONFIG'][$key] ?? $default;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int)($c['port'] ?? 3306), $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    }
    return $pdo;
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name(config('session_name', 'TCKTSESS'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => app_base() . '/',
        'secure'   => (bool)config('secure_cookie', false),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

start_session();

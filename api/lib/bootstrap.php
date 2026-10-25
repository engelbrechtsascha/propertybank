<?php
declare(strict_types=1);

/**
 * Loads configuration, configures error handling, time zone and the session,
 * and pulls in the rest of the library. Every entry point requires this file
 * first. Same shape as the Pricer and MarketData.
 */

require_once __DIR__ . '/version.php';

$configFile = dirname(__DIR__) . '/config.php';

if (!is_file($configFile)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => 'not_installed',
        'message' => 'The application is not configured yet. Open install.php in your browser.',
    ]);
    exit;
}

/** @var array<string,mixed> $config */
$config = require $configFile;

$GLOBALS['pb_config'] = $config;

function cfg(string $key, mixed $default = null): mixed
{
    return $GLOBALS['pb_config'][$key] ?? $default;
}

$tz = (string) cfg('timezone', 'Europe/Berlin');
if (!@date_default_timezone_set($tz)) {
    date_default_timezone_set('Europe/Berlin');
}

// ---------------------------------------------------------------- errors ---
$debug = (bool) cfg('debug', false);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

set_exception_handler(static function (Throwable $e) use ($debug): void {
    error_log('[propertybank] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'error' => 'server_error',
        'message' => $debug ? $e->getMessage() : 'Something went wrong on the server. Please try again.',
    ]);
    exit;
});

/** The URL path of this installation with a trailing slash: "/propertybank/". */
function app_base_path(): string
{
    $override = trim((string) cfg('cookie_path', ''));
    if ($override !== '') {
        return rtrim($override, '/') . '/';
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/'));
    $dir = rtrim(dirname($script), '/');
    if (str_ends_with($dir, '/api')) {
        $dir = substr($dir, 0, -4);
    }
    return $dir === '' ? '/' : $dir . '/';
}

// --------------------------------------------------------------- session ---
function pb_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_name((string) cfg('session_name', 'pbsid'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => app_base_path(),
        'httponly' => true,
        'secure'   => $https,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require __DIR__ . '/http.php';
require __DIR__ . '/db.php';
require __DIR__ . '/settings.php';
require __DIR__ . '/repo.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/tokens.php';
require __DIR__ . '/fetch.php';
require __DIR__ . '/translit.php';
require __DIR__ . '/geo.php';
require __DIR__ . '/fx.php';
require __DIR__ . '/cities.php';
require __DIR__ . '/olx.php';
require __DIR__ . '/translate.php';
require __DIR__ . '/listings.php';
require __DIR__ . '/refresh.php';

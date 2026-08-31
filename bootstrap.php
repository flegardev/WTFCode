<?php

declare(strict_types=1);

$composerAutoload = __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (!is_file($composerAutoload)) {
    $message = 'WTFCode dependencies are missing. Run "composer install" in the project root.';
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . PHP_EOL);
    } else {
        http_response_code(503);
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');
        }
        echo $message;
    }
    exit(1);
}
require_once $composerAutoload;

$config = app_config();
if ($config['environment'] === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
}

set_exception_handler(static function (Throwable $exception): void {
    Logger::error('Unhandled application exception', ['type' => get_class($exception), 'message' => $exception->getMessage()]);
    if (PHP_SAPI === 'cli') {
        $config = app_config();
        fwrite(STDERR, $config['debug'] ? 'Application error: ' . SensitiveDataSanitizer::text($exception->getMessage()) . PHP_EOL : 'Application error.' . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    $config = app_config();
    exit($config['debug'] ? 'Application error: ' . SensitiveDataSanitizer::text($exception->getMessage()) : 'Something went wrong. Please try again.');
});

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    send_security_headers();
}

if (PHP_SAPI !== 'cli' && !defined('WTF_CODE_NO_SESSION') && session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) $config['session_lifetime']);
    session_name('wtfcode_session');
    if ($config['session_driver'] === 'database') {
        session_set_save_handler(new DatabaseSessionHandler((int) $config['session_lifetime']), true);
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => request_is_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

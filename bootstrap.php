<?php

declare(strict_types=1);

$composerAutoload = __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

spl_autoload_register(static function (string $class): void {
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
    foreach ([__DIR__ . DIRECTORY_SEPARATOR . 'src', __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Analysis'] as $root) {
        $candidate = $root . DIRECTORY_SEPARATOR . $relative;
        if (is_file($candidate)) {
            require_once $candidate;
            return;
        }
        $candidate = $root . DIRECTORY_SEPARATOR . basename($relative);
        if (is_file($candidate)) {
            require_once $candidate;
            return;
        }
    }
});

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

foreach ([
    'src/Database.php',
    'src/Logger.php',
    'src/helpers.php',
    'src/Auth.php',
    'src/RepositoryImporter.php',
    'src/RepoScanner.php',
    'src/Project.php',
    'src/ExplorationService.php',
    'src/PromptSafetyService.php',
    'src/ExplanationService.php',
    'src/GitDiffService.php',
] as $file) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . $file;
}

set_exception_handler(static function (Throwable $exception): void {
    Logger::error('Unhandled application exception', ['type' => get_class($exception), 'message' => $exception->getMessage()]);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Application error: ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    $config = app_config();
    if ($config['environment'] === 'local') {
        exit('Application error: ' . e($exception->getMessage()));
    }
    exit('Something went wrong. Please try again.');
});

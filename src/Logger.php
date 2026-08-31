<?php

declare(strict_types=1);

final class Logger
{
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $safeContext = array_filter($context, static fn ($key): bool => !in_array(strtolower((string) $key), ['password', 'db_password', 'database_url', 'token', 'authorization'], true), ARRAY_FILTER_USE_KEY);
        $message = SensitiveDataSanitizer::text($message);
        $safeContext = SensitiveDataSanitizer::scrub($safeContext);
        $line = sprintf("[%s] %s %s %s%s", date('c'), $level, $message, json_encode($safeContext, JSON_UNESCAPED_SLASHES), PHP_EOL);
        if ((app_config()['environment'] ?? 'local') === 'production') {
            @file_put_contents('php://stderr', $line, FILE_APPEND);
            return;
        }
        $logDirectory = app_config()['storage_path'] . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logDirectory)) @mkdir($logDirectory, 0700, true);
        @file_put_contents($logDirectory . DIRECTORY_SEPARATOR . 'app.log', $line, FILE_APPEND | LOCK_EX);
    }
}

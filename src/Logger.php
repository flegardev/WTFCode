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
        $safeContext = array_filter($context, static fn ($key): bool => !in_array(strtolower((string) $key), ['password', 'token', 'authorization'], true), ARRAY_FILTER_USE_KEY);
        $line = sprintf("[%s] %s %s %s%s", date('c'), $level, $message, json_encode($safeContext, JSON_UNESCAPED_SLASHES), PHP_EOL);
        @file_put_contents(__DIR__ . '/../storage/logs/app.log', $line, FILE_APPEND | LOCK_EX);
    }
}


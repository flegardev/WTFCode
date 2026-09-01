<?php

declare(strict_types=1);

$environmentValue = static function (string ...$names): ?string {
    foreach ($names as $name) {
        $value = getenv($name);
        if (is_string($value) && $value !== '') return $value;
    }
    return null;
};

$booleanValue = static function (?string $value, bool $default = false): bool {
    if ($value === null) return $default;
    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed ?? $default;
};

$projectRoot = dirname(__DIR__);
$storagePath = $environmentValue('WTF_STORAGE_PATH') ?? ($projectRoot . DIRECTORY_SEPARATOR . 'storage');
$appEnvironment = strtolower($environmentValue('APP_ENV', 'WTF_CODE_ENV') ?? 'local');
$databaseUrl = $environmentValue('DATABASE_URL');
$urlConfiguration = [];

if ($databaseUrl !== null) {
    $parts = parse_url($databaseUrl);
    if (!is_array($parts)) {
        throw new RuntimeException('DATABASE_URL must be a valid PostgreSQL connection URL.');
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if ($scheme === 'sqlite') {
        $urlConfiguration = [
            'driver' => 'sqlite',
            'database' => rawurldecode(ltrim((string) $parts['path'], '/')),
        ];
    } elseif (in_array($scheme, ['postgres', 'postgresql'], true) && !empty($parts['host']) && !empty($parts['path'])) {
        parse_str((string) ($parts['query'] ?? ''), $query);
        $urlConfiguration = [
            'driver' => 'pgsql',
            'host' => (string) $parts['host'],
            'port' => (string) ($parts['port'] ?? 5432),
            'database' => rawurldecode(ltrim((string) $parts['path'], '/')),
            'username' => rawurldecode((string) ($parts['user'] ?? '')),
            'password' => rawurldecode((string) ($parts['pass'] ?? '')),
            'sslmode' => (string) ($query['sslmode'] ?? ''),
        ];
    } else {
        throw new RuntimeException('DATABASE_URL must be a valid PostgreSQL or SQLite connection URL.');
    }
}

$driver = strtolower((string) ($urlConfiguration['driver'] ?? $environmentValue('DB_DRIVER') ?? 'mysql'));
if (!in_array($driver, ['pgsql', 'mysql', 'sqlite'], true)) {
    throw new RuntimeException('DB_DRIVER must be pgsql, mysql, or sqlite.');
}

$defaultSqlitePath = $storagePath . DIRECTORY_SEPARATOR . 'database.sqlite';

return [
    'driver' => $driver,
    'host' => $urlConfiguration['host'] ?? $environmentValue('DB_HOST', 'WTF_CODE_DB_HOST') ?? '127.0.0.1',
    'port' => $urlConfiguration['port'] ?? $environmentValue('DB_PORT', 'WTF_CODE_DB_PORT') ?? ($driver === 'pgsql' ? '5432' : '3306'),
    'database' => $urlConfiguration['database'] ?? $environmentValue('DB_DATABASE', 'WTF_CODE_DB_NAME') ?? ($driver === 'sqlite' ? $defaultSqlitePath : 'wtfcode'),
    'username' => $urlConfiguration['username'] ?? $environmentValue('DB_USERNAME', 'WTF_CODE_DB_USER') ?? ($driver === 'pgsql' ? 'postgres' : ($driver === 'sqlite' ? '' : 'root')),
    'password' => $urlConfiguration['password'] ?? $environmentValue('DB_PASSWORD', 'WTF_CODE_DB_PASSWORD') ?? '',
    'sslmode' => ($urlConfiguration['sslmode'] ?? '') ?: ($environmentValue('DB_SSLMODE') ?? ($appEnvironment === 'production' && $driver === 'pgsql' ? 'require' : 'prefer')),
    'charset' => 'utf8mb4',
    'app_url' => rtrim($environmentValue('APP_URL', 'WTF_CODE_APP_URL') ?? '', '/'),
    'environment' => $appEnvironment,
    'debug' => $booleanValue($environmentValue('APP_DEBUG'), $appEnvironment === 'local'),
    'storage_path' => rtrim($storagePath, '\\/'),
    'scan_profile' => $environmentValue('WTF_SCAN_PROFILE') ?? 'quick',
    'session_driver' => strtolower($environmentValue('SESSION_DRIVER') ?? ($appEnvironment === 'production' ? 'database' : 'files')),
    'session_lifetime' => max(900, (int) ($environmentValue('SESSION_LIFETIME') ?? '7200')),
    'version' => substr($environmentValue('APP_VERSION', 'VERCEL_GIT_COMMIT_SHA') ?? 'dev', 0, 64),
    'vercel' => $booleanValue($environmentValue('VERCEL')),
    'github_app_id' => $environmentValue('GITHUB_APP_ID') ?? '',
    'github_app_slug' => $environmentValue('GITHUB_APP_SLUG') ?? '',
    'github_app_client_id' => $environmentValue('GITHUB_APP_CLIENT_ID') ?? '',
    'github_app_client_secret' => $environmentValue('GITHUB_APP_CLIENT_SECRET') ?? '',
    'github_app_private_key' => $environmentValue('GITHUB_APP_PRIVATE_KEY') ?? '',
    'github_app_callback_url' => $environmentValue('GITHUB_APP_CALLBACK_URL') ?? '',
];

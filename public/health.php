<?php

declare(strict_types=1);

define('WTF_CODE_NO_SESSION', true);
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
$database = 'unavailable';
try {
    // Verify the request/session and worker queue contracts, not just one table.
    Database::connection()->query('SELECT id, is_suspended FROM users LIMIT 0');
    Database::connection()->query('SELECT id, state, lease_token, attempt_count, max_attempts, available_at FROM analysis_jobs LIMIT 0');
    Database::connection()->query('SELECT id, user_id, status FROM projects LIMIT 0');
    Database::connection()->query('SELECT session_id, payload, expires_at FROM sessions LIMIT 0');
    $ready = true;
    $database = $ready ? 'ready' : 'unavailable';
} catch (Throwable) {
    $database = 'unavailable';
}
$ok = $database === 'ready';
http_response_code($ok ? 200 : 503);
echo json_encode(['status' => $ok ? 'ok' : 'degraded', 'database' => $database, 'version' => app_config()['version']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

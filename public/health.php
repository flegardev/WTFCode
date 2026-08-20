<?php

declare(strict_types=1);

define('WTF_CODE_NO_SESSION', true);
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
$database = 'unavailable';
try {
    $ready = Database::connection()->query("SELECT to_regclass('public.users') IS NOT NULL")->fetchColumn();
    $database = $ready ? 'ready' : 'unavailable';
} catch (Throwable) {
    $database = 'unavailable';
}
$ok = $database === 'ready';
http_response_code($ok ? 200 : 503);
echo json_encode(['status' => $ok ? 'ok' : 'degraded', 'database' => $database, 'version' => app_config()['version']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

<?php

declare(strict_types=1);
define('WTF_CODE_NO_SESSION', true);
require_once __DIR__ . '/../bootstrap.php';
if (PHP_SAPI !== 'cli') exit(2);
try {
    $q = Database::connection()->prepare("SELECT COUNT(*) FROM worker_heartbeats WHERE worker_id LIKE ? AND last_seen_at > CURRENT_TIMESTAMP - INTERVAL '5 minutes'");
    $host = preg_replace('/[^A-Za-z0-9_.-]/', '-', (string) (gethostname() ?: 'host')) ?: 'host';
    $q->execute([$host . ':%']);
    exit((int) $q->fetchColumn() > 0 ? 0 : 1);
} catch (Throwable) { exit(1); }

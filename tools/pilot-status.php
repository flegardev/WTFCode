<?php

declare(strict_types=1);
define('WTF_CODE_NO_SESSION', true);
require_once __DIR__ . '/../bootstrap.php';
if (PHP_SAPI !== 'cli') exit(2);
use WTFCode\Application\PilotAccess;
use WTFCode\Application\PilotOperations;
try {
    $status = PilotOperations::status();
    $production = app_config()['environment'] === 'production';
    $gates = [
        'bounded_allowlist' => PilotAccess::mode() === 'allowlist' && count(PilotAccess::emails()) >= 1 && count(PilotAccess::emails()) <= 5,
        'database_tls' => !$production || in_array(app_config()['host'], ['database', '127.0.0.1', 'localhost'], true) || app_config()['sslmode'] === 'verify-full',
        'verification' => PilotAccess::requiresVerification(),
        'database_sessions' => app_config()['session_driver'] === 'database',
        'private_imports_disabled' => !app_config()['private_repositories_enabled'],
        'local_explanations' => (getenv('WTF_CODE_EXPLANATION_PROVIDER') ?: 'deterministic') === 'deterministic',
        'canonical_origin' => parse_url(app_config()['app_url'], PHP_URL_SCHEME) === ($production ? 'https' : 'http'),
        'mail' => $production ? getenv('WTF_MAIL_TRANSPORT') === 'mail' && getenv('WTF_LIVE_MAIL_APPROVED') === 'true' && (bool) filter_var(getenv('WTF_MAIL_FROM'), FILTER_VALIDATE_EMAIL) : getenv('WTF_MAIL_TRANSPORT') === 'file',
        'worker' => (int) $status['live_workers'] > 0,
        'disk_headroom' => $status['disk_free_bytes'] !== false && $status['disk_free_bytes'] >= 536870912,
        'mail_retries' => $status['mail_exhausted'] === 0,
    ];
    echo json_encode(['status' => in_array(false, $gates, true) ? 'blocked' : ($production ? 'ready-for-hosted-smoke-test' : 'local-controls-passed'), 'environment' => app_config()['environment'], 'gates' => $gates, 'operations' => $status], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(in_array(false, $gates, true) ? 1 : 0);
} catch (Throwable) { fwrite(STDERR, "Pilot database or schema unavailable.\n"); exit(1); }

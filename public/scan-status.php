<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$respond = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    $body = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT,
    );
    if (!is_string($body)) {
        http_response_code(500);
        echo '{"error":"Scan status is temporarily unavailable."}';
        exit;
    }
    echo $body;
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    $respond(['error' => 'Method not allowed.'], 405);
}
if (!Auth::check() || Auth::id() === null) {
    $respond(['error' => 'Your session expired. Sign in again.'], 401);
}

$projectId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($projectId === false || $projectId === null || $projectId < 1) {
    $respond(['error' => 'Project not found.'], 404);
}

$project = Project::findForUser((int) $projectId, (int) Auth::id());
if ($project === null) {
    $respond(['error' => 'Project not found.'], 404);
}

$job = AnalysisJobStore::latestForUser((int) $projectId, (int) Auth::id());
if ($job === null) {
    $respond([
        'project_status' => (string) $project['status'],
        'evidence_ready' => !empty($project['last_scan_at']),
        'job' => null,
    ]);
}

$steps = is_array($job['steps'] ?? null) ? $job['steps'] : [];
$stepStates = array_count_values(array_map(static fn(array $step): string => (string) ($step['state'] ?? 'unknown'), $steps));
$state = (string) $job['state'];
$terminal = in_array($state, ['completed', 'partial', 'failed'], true);
$safeError = $state === 'failed'
    ? 'The repository analysis did not finish. Try the scan again or choose Quick analysis.'
    : null;

$respond([
    'project_status' => (string) $project['status'],
    'evidence_ready' => !empty($project['last_scan_at']),
    'job' => [
        'id' => (int) $job['id'],
        'kind' => (string) ($job['job_kind'] ?? 'rescan'),
        'profile' => (string) $job['analysis_profile'],
        'state' => $state,
        'stage' => (string) ($job['current_stage'] ?? ucfirst($state)),
        'progress_current' => max(0, (int) ($job['progress_current'] ?? 0)),
        'progress_total' => max(0, (int) ($job['progress_total'] ?? 0)),
        'attempt' => max(0, (int) ($job['attempt_count'] ?? 0)),
        'max_attempts' => max(1, (int) ($job['max_attempts'] ?? 1)),
        'terminal' => $terminal,
        'error' => $safeError,
        'steps' => [
            'total' => count($steps),
            'completed' => (int) ($stepStates['completed'] ?? 0),
            'partial' => (int) ($stepStates['partial'] ?? 0),
            'failed' => (int) ($stepStates['failed'] ?? 0),
        ],
    ],
]);

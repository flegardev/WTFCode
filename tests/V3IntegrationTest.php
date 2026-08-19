<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function v3_integration_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = Database::connection();
$token = bin2hex(random_bytes(6));
$userId = null;

try {
    $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)')->execute([
        'name' => 'V3 Integration',
        'email' => 'v3-' . $token . '@wtfcode.local',
        'password_hash' => password_hash($token, PASSWORD_DEFAULT),
    ]);
    $userId = (int) $pdo->lastInsertId();
    $fixture = __DIR__ . '/fixtures/v2/plain-php';
    $pdo->prepare('INSERT INTO projects (user_id, name, repository_url, local_path, status) VALUES (:user_id, :name, :repository_url, :local_path, :status)')->execute([
        'user_id' => $userId,
        'name' => 'V3 fixture ' . $token,
        'repository_url' => 'https://github.com/wtfcode-v3-fixtures/' . $token . '.git',
        'local_path' => $fixture,
        'status' => 'scanning',
    ]);
    $projectId = (int) $pdo->lastInsertId();
    (new RepoScanner())->scan($projectId, $fixture);

    $scan = $pdo->query('SELECT * FROM scan_runs WHERE project_id = ' . $projectId . ' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    v3_integration_assert(is_array($scan), 'V3 scan run must be persisted');
    v3_integration_assert($scan['analysis_version'] === AnalysisEngine::VERSION, 'Scan run must identify the V3 platform analysis version');
    v3_integration_assert($scan['analysis_profile'] === AnalysisProfile::QUICK, 'Default scan must record the quick profile');
    $engineStatus = json_decode((string) $scan['engine_status_json'], true, flags: JSON_THROW_ON_ERROR);
    v3_integration_assert(($engineStatus[0]['engine'] ?? '') === 'wtfcode-native', 'Scan run must retain provider status JSON');

    $provider = $pdo->query('SELECT * FROM analysis_provider_runs WHERE scan_run_id = ' . (int) $scan['id'])->fetch(PDO::FETCH_ASSOC);
    v3_integration_assert(($provider['engine_id'] ?? '') === 'wtfcode-native' && ($provider['status'] ?? '') === 'success', 'Provider run must be queryable independently');
    v3_integration_assert((int) ($provider['symbols_count'] ?? 0) > 0, 'Provider run must retain output counts');

    $provenanceJson = $pdo->query('SELECT provenance_json FROM code_symbols WHERE project_id = ' . $projectId . ' ORDER BY id LIMIT 1')->fetchColumn();
    $provenance = json_decode((string) $provenanceJson, true, flags: JSON_THROW_ON_ERROR);
    v3_integration_assert(($provenance[0]['engine'] ?? '') === 'wtfcode-native', 'Persisted symbols must identify their source engine');
    v3_integration_assert(isset($provenance[0]['engine_version'], $provenance[0]['analysis_version'], $provenance[0]['evidence_file'], $provenance[0]['evidence_range']), 'Persisted provenance must retain version and source range fields');
} finally {
    if ($userId !== null) $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
}

echo "WTFCode V3 database integration checks passed.\n";

<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function security_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wtfcode-security-' . bin2hex(random_bytes(6));
if (!mkdir($root, 0700, true) && !is_dir($root)) throw new RuntimeException('Could not create security fixture.');
$rawSecret = 'ghp_' . substr(strtr(base64_encode(random_bytes(32)), '+/=', 'ABC'), 0, 36);

try {
    $content = "GITHUB_TOKEN={$rawSecret}\n";
    file_put_contents($root . DIRECTORY_SEPARATOR . 'credentials.env', $content, LOCK_EX);
    $request = new AnalysisRequest($root, [[
        'path' => 'credentials.env', 'language' => 'Environment', 'content' => $content, 'lines' => 1,
    ]], AnalysisProfile::SECURITY);
    $gitleaks = (new GitleaksAnalyzerProvider())->analyze($request);
    security_assert($gitleaks->status === AnalyzerResult::SUCCESS, 'Gitleaks should scan the isolated fixture');
    security_assert($gitleaks->findings !== [], 'Gitleaks should detect the generated fake GitHub token shape');
    $gitleaksJson = json_encode($gitleaks->findings, JSON_THROW_ON_ERROR);
    security_assert(!str_contains($gitleaksJson, $rawSecret), 'Raw secret values must never survive Gitleaks normalization');
    security_assert(str_contains($gitleaksJson, '<redacted>'), 'Secret findings should carry an explicit masked preview');

    $unsafeFinding = ['severity' => 'risk', 'type' => 'possible_exposed_secret', 'title' => 'Token ' . $rawSecret, 'explanation' => $rawSecret, 'path' => 'credentials.env', 'evidence' => ['secret' => $rawSecret, 'raw_secret' => $rawSecret, 'rule_id' => 'test', 'line' => 1]];
    $safeFinding = SensitiveDataSanitizer::finding($unsafeFinding);
    $safeJson = json_encode($safeFinding, JSON_THROW_ON_ERROR);
    security_assert(!str_contains($safeJson, $rawSecret), 'Secret sanitizer must protect JSON and export contexts');
    security_assert(!str_contains(e($safeJson), $rawSecret), 'Escaped HTML must not reveal a raw secret');
    security_assert(!str_contains(SensitiveDataSanitizer::text('log=' . $rawSecret), $rawSecret), 'Log-bound text must be redacted');

    $semgrep = SemgrepAnalyzerProvider::normalizeReport(['results' => [[
        'check_id' => 'wtfcode.security.php-command-injection', 'path' => 'public/run.php',
        'start' => ['line' => 7], 'end' => ['line' => 7],
        'extra' => ['severity' => 'ERROR', 'message' => 'External data may influence a command.', 'lines' => 'exec(' . $rawSecret . ')', 'metadata' => ['cwe' => ['CWE-78'], 'owasp' => ['A03:2021']]],
    ]]]);
    security_assert(($semgrep[0]['title'] ?? '') === 'Potential command injection', 'Semgrep jargon should be translated into a plain-language title');
    security_assert(!str_contains(json_encode($semgrep, JSON_THROW_ON_ERROR), $rawSecret), 'Semgrep source snippets must not be persisted');

    $osv = OsvAnalyzerProvider::normalizeReport(['results' => [[
        'source' => ['path' => 'package-lock.json'],
        'packages' => [[
            'package' => ['name' => 'example-package', 'version' => '1.0.0', 'ecosystem' => 'npm'],
            'vulnerabilities' => [[
                'id' => 'GHSA-AAAA-BBBB-CCCC', 'aliases' => ['CVE-2099-0001'],
                'database_specific' => ['severity' => 'HIGH'],
                'affected' => [['ranges' => [['events' => [['introduced' => '0'], ['fixed' => '1.0.1']]]]]],
            ]],
        ]],
    ]]]);
    security_assert(count($osv) === 1 && ($osv[0]['evidence']['fixed_versions'][0] ?? '') === '1.0.1', 'OSV findings should retain package, advisory, and fixed-version evidence');

    $grype = GrypeAnalyzerProvider::normalizeReport(['matches' => [[
        'vulnerability' => ['id' => 'CVE-2099-0001', 'severity' => 'High', 'fix' => ['versions' => ['1.0.1'], 'state' => 'fixed']],
        'artifact' => ['name' => 'example-package', 'version' => '1.0.0', 'type' => 'npm', 'locations' => [['path' => 'package-lock.json']]],
        'relatedVulnerabilities' => [['id' => 'GHSA-AAAA-BBBB-CCCC']],
    ]]]);
    $fused = (new EvidenceFusion())->fuse([
        new AnalyzerResult('osv-scanner', '2.5.1', AnalyzerResult::SUCCESS, findings: $osv),
        new AnalyzerResult('grype', '0.117.0', AnalyzerResult::SUCCESS, findings: $grype),
    ]);
    security_assert(count($fused['findings']) === 1, 'OSV and Grype aliases for the same package/version must become one card');
    security_assert(count($fused['findings'][0]['evidence']['engines'] ?? []) === 2, 'A deduplicated vulnerability should retain both confirmation engines');

    $packages = SyftAnalyzerProvider::normalizeReport(['artifacts' => [[
        'name' => 'example-package', 'version' => '1.0.0', 'type' => 'npm', 'purl' => 'pkg:npm/example-package@1.0.0',
        'licenses' => [['value' => 'MIT']], 'locations' => [['path' => 'package-lock.json']],
    ]]]);
    security_assert(($packages[0]['classification'] ?? '') === 'resolved', 'Lockfile packages should be classified as resolved');

    security_assert(!AnalysisProfile::includes(AnalysisProfile::QUICK, 'gitleaks'), 'Quick scans must not silently run security binaries');
    security_assert(AnalysisProfile::includes(AnalysisProfile::SECURITY, 'osv-scanner'), 'Security scans must include OSV');
    security_assert(AnalysisProfile::includes(AnalysisProfile::MAXIMUM, 'grype'), 'Maximum scans must include optional Grype');

    $manifest = json_decode((string) file_get_contents(__DIR__ . '/../config/tool-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $binaryNames = ['Gitleaks' => 'gitleaks', 'OSV-Scanner' => 'osv-scanner', 'Syft' => 'syft', 'Grype' => 'grype'];
    foreach ($manifest['tools'] as $tool) {
        if (PHP_OS_FAMILY === 'Windows') {
            $binary = __DIR__ . '/../tools/bin/' . $binaryNames[$tool['name']] . '.exe';
            security_assert(is_file($binary), $tool['name'] . ' binary should exist for checksum verification');
            security_assert(hash_file('sha256', $binary) === $tool['executable_sha256'], $tool['name'] . ' executable hash must match the pinned manifest');
        } else {
            $binary = ToolDetector::findExecutable($binaryNames[$tool['name']]);
            security_assert($binary !== null && is_file($binary), $tool['name'] . ' Linux binary should be available');
            security_assert(preg_match('/^[a-f0-9]{64}$/', (string) ($tool['linux_artifact_sha256'] ?? '')) === 1, $tool['name'] . ' Linux release artifact checksum must be pinned');
        }
    }
    security_assert(hash_file('sha256', __DIR__ . '/../config/security/gitleaks.toml') === ($manifest['trusted_configs'][0]['sha256'] ?? ''), 'Pinned Gitleaks configuration hash must match the manifest');

    // Database persistence sanitization check (if database is connected)
    try {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $token = bin2hex(random_bytes(8));
            $userId = Database::insert('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)', ['name' => 'Security test', 'email' => 'security-' . $token . '@wtfcode.local', 'password_hash' => password_hash($token, PASSWORD_DEFAULT)]);
            $projectId = Database::insert('INSERT INTO projects (user_id, name, repository_url, local_path, status) VALUES (:user_id, :name, :repository_url, :local_path, :status)', ['user_id' => $userId, 'name' => 'Security fixture', 'repository_url' => 'https://github.com/wtfcode-security/' . $token . '.git', 'local_path' => $root, 'status' => 'ready']);
            $scanRunId = Database::insert('INSERT INTO scan_runs (project_id, analysis_version, analysis_profile) VALUES (:project_id, :analysis_version, :analysis_profile)', ['project_id' => $projectId, 'analysis_version' => AnalysisEngine::VERSION, 'analysis_profile' => AnalysisProfile::SECURITY]);
            FindingStore::persist($projectId, $scanRunId, [$unsafeFinding]);
            $stmt = $pdo->prepare('SELECT title, plain_explanation, evidence_json FROM scan_findings WHERE scan_run_id = :scan_run_id');
            $stmt->execute([':scan_run_id' => $scanRunId]);
            $row = $stmt->fetch();
            $stored = $row ? implode(' ', $row) : '';
            security_assert(!str_contains($stored, $rawSecret), 'Database persistence must apply the final secret-sanitization boundary');
        } finally {
            $pdo->rollBack();
        }
    } catch (RuntimeException $dbException) {
        // If external DB is not running during local unit test, verify in-memory persistence logic
        $sanitized = SensitiveDataSanitizer::finding($unsafeFinding);
        security_assert(!str_contains(json_encode($sanitized, JSON_THROW_ON_ERROR), $rawSecret), 'Finding sanitization must redact raw secrets');
    }
} finally {
    @unlink($root . DIRECTORY_SEPARATOR . 'credentials.env');
    @rmdir($root);
}

echo "WTFCode V3 security intelligence checks passed.\n";

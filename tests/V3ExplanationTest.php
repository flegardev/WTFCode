<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function explanation_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

foreach ([new DeterministicExplanationProvider(), new OpenAiCompatibleExplanationProvider(), new OllamaExplanationProvider()] as $provider) explanation_assert($provider instanceof ExplanationProviderInterface, 'Every explanation provider must implement the interface');

$pdo = Database::connection();
$token = bin2hex(random_bytes(6));
$rawSecret = 'github_pat_' . str_repeat('A', 40) . $token;
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wtfcode-explain-' . $token;
mkdir($directory, 0700, true);
mkdir($directory . DIRECTORY_SEPARATOR . 'auth', 0700, true);
mkdir($directory . DIRECTORY_SEPARATOR . 'chat', 0700, true);
$userId = null;
$previousProvider = getenv('WTF_CODE_EXPLANATION_PROVIDER');
try {
    file_put_contents($directory . DIRECTORY_SEPARATOR . 'AuthService.php', "<?php\nfinal class AuthService { public function login(): bool { \$token = '" . $rawSecret . "'; return password_verify('x', 'y'); } }\n");
    file_put_contents($directory . DIRECTORY_SEPARATOR . 'LoginController.php', "<?php\nrequire_once __DIR__ . '/AuthService.php';\nfinal class LoginController { public function login(): bool { return (new AuthService())->login(); } }\n");
    file_put_contents($directory . DIRECTORY_SEPARATOR . 'UsedHelper.js', "export function usedHelper() { return true; }\n");
    file_put_contents($directory . DIRECTORY_SEPARATOR . 'Consumer.js', "import { usedHelper } from './UsedHelper.js';\nexport const result = usedHelper();\n");
    file_put_contents($directory . DIRECTORY_SEPARATOR . 'UnusedHelper.php', "<?php\nfunction unused_helper(): string { return 'maybe dynamic'; }\n");
    file_put_contents($directory . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'route.js', "export const route = 'auth';\n");
    file_put_contents($directory . DIRECTORY_SEPARATOR . 'chat' . DIRECTORY_SEPARATOR . 'route.js', "export const route = 'chat';\n");
    file_put_contents($directory . DIRECTORY_SEPARATOR . 'ChatConsumer.js', "import { route } from './chat/route.js';\nexport const selected = route;\n");
    $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)')->execute(['name' => 'Explanation test', 'email' => 'explain-' . $token . '@wtfcode.local', 'password_hash' => password_hash($token, PASSWORD_DEFAULT)]);
    $userId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO projects (user_id, name, repository_url, local_path, status) VALUES (:user_id, :name, :repository_url, :local_path, :status)')->execute(['user_id' => $userId, 'name' => 'Explanation fixture', 'repository_url' => 'https://github.com/wtfcode-explain/' . $token . '.git', 'local_path' => $directory, 'status' => 'scanning']);
    $projectId = (int) $pdo->lastInsertId();
    (new RepoScanner())->scan($projectId, $directory, AnalysisProfile::QUICK);
    putenv('WTF_CODE_EXPLANATION_PROVIDER=deterministic');
    $answer = ExplanationManager::answer($projectId, 'How does login work?');
    explanation_assert($answer['provider'] === 'deterministic' && $answer['provider_fallback'] === false, 'Deterministic explanations must work without AI');
    explanation_assert(($answer['evidence_packet']['evidence'] ?? []) !== [], 'Evidence packet must include numbered evidence');
    explanation_assert(preg_match('/\[\d+\]/', $answer['answer']) === 1, 'Deterministic supported claims must include citations');
    $serialized = json_encode($answer['evidence_packet'], JSON_THROW_ON_ERROR);
    explanation_assert(!str_contains($serialized, $rawSecret), 'Raw secrets must never enter an explanation packet');
    explanation_assert(strlen($serialized) < 262144, 'Evidence packets must remain bounded');
    $usedDeletion = ExplanationService::deterministicAnswer($projectId, 'Can I delete UsedHelper.js?');
    explanation_assert(str_starts_with($usedDeletion['answer'], 'Confirmed use:') && str_contains($usedDeletion['answer'], 'Do not delete'), 'Deletion answers must warn when confirmed dependents exist');
    $unusedDeletion = ExplanationService::deterministicAnswer($projectId, 'Can I delete UnusedHelper.php?');
    explanation_assert(str_starts_with($unusedDeletion['answer'], 'No detected use:') && str_contains($unusedDeletion['answer'], 'not proof'), 'No detected reference must never mean safe to delete');
    $duplicateDeletion = ExplanationService::deterministicAnswer($projectId, 'Can I delete chat/route.js?');
    explanation_assert(str_contains($duplicateDeletion['answer'], 'chat/route.js') && !str_contains($duplicateDeletion['answer'], 'auth/route.js'), 'A full-path deletion question must not resolve to a different file with the same basename');

    $packet = EvidencePacket::build($projectId, 'login');
    $normalized = CitationEnforcer::normalize('{"answer":"Auth is present [1]. Runtime behavior may vary.","citations":[1,999]}', $packet);
    explanation_assert(!in_array(999, $normalized['citations'], true), 'Unknown citation IDs must be rejected');
    explanation_assert(str_contains($normalized['answer'], 'Inference: Runtime behavior may vary.'), 'Unsupported claims must be labeled as inference');
    putenv('WTF_CODE_EXPLANATION_PROVIDER=openai-compatible');
    putenv('WTF_CODE_OPENAI_API_KEY=');
    $fallback = ExplanationManager::answer($projectId, 'login');
    explanation_assert($fallback['provider'] === 'deterministic' && $fallback['provider_fallback'] === true, 'Unavailable optional providers must fall back independently');
} finally {
    if ($previousProvider === false) putenv('WTF_CODE_EXPLANATION_PROVIDER'); else putenv('WTF_CODE_EXPLANATION_PROVIDER=' . $previousProvider);
    if ($userId !== null) $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    @rmdir($directory);
}

echo "WTFCode V3 explanation layer checks passed.\n";

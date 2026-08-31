<?php

declare(strict_types=1);

define('WTF_CODE_NO_SESSION', true);
require_once __DIR__ . '/../bootstrap.php';

function import_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$projectId = random_int(800000000, 900000000);
$url = 'https://github.com/octocat/Hello-World.git';
$path = null;
try {
    $path = RepositoryImporter::clone($projectId, $url);
    import_assert(is_dir($path . DIRECTORY_SEPARATOR . '.git'), 'Known public repository must clone without a GitHub connection.');
    $samePath = RepositoryImporter::workingCopy(['id' => $projectId, 'user_id' => 1, 'repository_url' => $url, 'local_path' => $path]);
    import_assert($samePath === $path, 'Public working-copy reuse must remain available for rescan.');
    RepositoryImporter::cleanup($path);
    $path = RepositoryImporter::workingCopy(['id' => $projectId, 'user_id' => 1, 'repository_url' => $url, 'local_path' => '']);
    import_assert(is_dir($path . DIRECTORY_SEPARATOR . '.git'), 'Public rescan must rehydrate a missing working copy.');
    try {
        RepositoryImporter::clone($projectId + 1, 'https://github.com/wtfcode-does-not-exist-987654321/no-repository-here.git');
        throw new RuntimeException('Nonexistent public repository unexpectedly cloned.');
    } catch (GitHubAccessException $exception) {
        import_assert($exception->reason === 'repository_unavailable', 'Nonexistent public repositories must return a safe classified error.');
    }
    import_assert(RepositoryImporter::normalizeGithubUrl('file:///tmp/repository') === null, 'Malformed clone targets must remain rejected.');
} finally {
    if (is_string($path)) RepositoryImporter::cleanup($path);
}

fwrite(STDOUT, "Public repository clone, malformed/not-found handling, and rescan rehydration checks passed.\n");

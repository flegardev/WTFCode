<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function false_positive_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wtfcode-false-positive-' . bin2hex(random_bytes(6));
mkdir($root . DIRECTORY_SEPARATOR . 'docs', 0700, true);
mkdir($root . DIRECTORY_SEPARATOR . 'tests', 0700, true);
mkdir($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Analysis', 0700, true);
mkdir($root . DIRECTORY_SEPARATOR . 'views', 0700, true);
try {
    file_put_contents($root . DIRECTORY_SEPARATOR . 'README.md', 'Next.js Stripe Firebase OpenAI Docker Kubernetes https://api.stripe.com');
    file_put_contents($root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'architecture.md', 'Supabase and Sentry are examples only.');
    file_put_contents($root . DIRECTORY_SEPARATOR . 'styles.css', '.next { content: "Stripe OpenAI"; }');
    file_put_contents($root . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'copy.blade.php', '<p>Stripe and https://api.openai.com are prose.</p>');
    file_put_contents($root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixture.php', '<?php // Firebase Stripe Supabase detector fixture');
    file_put_contents($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Analysis' . DIRECTORY_SEPARATOR . 'Detector.php', '<?php $patterns = ["Stripe", "Supabase", "OpenAI"];');
    file_put_contents($root . DIRECTORY_SEPARATOR . 'package.json', '{"name":"generic-next-key","config":{"next":"not-a-framework"}}');
    file_put_contents($root . DIRECTORY_SEPARATOR . 'package-lock.json', '{"lockfileVersion":3,"packages":{}}');
    $result = (new RepoScanner())->inspect($root, AnalysisProfile::QUICK);
    false_positive_assert(!in_array('Next.js', $result['stack'], true), 'A generic next key must not prove Next.js');
    false_positive_assert(!in_array('Docker', $result['stack'], true), 'README Docker prose must not prove Docker use');
    $services = array_values(array_filter($result['symbol_graph']['symbols'], static fn (array $symbol): bool => ($symbol['type'] ?? '') === 'external_service'));
    false_positive_assert($services === [], 'Docs, CSS, Blade prose, tests, and detector source must not prove external services');
    $runtimeFindings = array_values(array_filter($result['findings'], static fn (array $finding): bool => in_array($finding['path'] ?? '', ['README.md', 'styles.css', 'views/copy.blade.php', 'tests/fixture.php'], true) && in_array($finding['severity'] ?? '', ['attention', 'risk'], true)));
    false_positive_assert($runtimeFindings === [], 'Non-runtime prose must not become risk findings');
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    @rmdir($root);
}

echo "WTFCode V3 false-positive regression checks passed.\n";

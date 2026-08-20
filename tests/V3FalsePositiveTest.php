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
mkdir($root . DIRECTORY_SEPARATOR . '.playwright-cli', 0700, true);
try {
    file_put_contents($root . DIRECTORY_SEPARATOR . 'README.md', 'Next.js Stripe Firebase OpenAI Docker Kubernetes https://api.stripe.com SELECT * FROM the documentation');
    file_put_contents($root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'architecture.md', 'Supabase and Sentry are examples only.');
    file_put_contents($root . DIRECTORY_SEPARATOR . 'styles.css', '.next { content: "Stripe OpenAI"; }');
    file_put_contents($root . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'copy.blade.php', '<p>Stripe and https://api.openai.com are prose.</p>');
    file_put_contents($root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixture.php', '<?php $endpoint = "https://api.stripe.com"; // Firebase Supabase detector fixture');
    file_put_contents($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Analysis' . DIRECTORY_SEPARATOR . 'Detector.php', '<?php $patterns = ["Stripe", "Supabase", "OpenAI"];');
    file_put_contents($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'SymbolRepository.php', '<?php $signals = "client stripe supabase firebase openai";');
    file_put_contents($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'RuntimeLinks.js', "// See https://docs.example.dev/runtime\nconst WEBSITE = 'https://www.example.dev';\nconst API_BASE_URL = 'https://api.real-service.dev/v1';\nfetch(API_BASE_URL + '/items');\n");
    file_put_contents($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'ClientConfig.ts', "new PaymentSdk(key, { apiVersion: null, appInfo: { url: 'https://github.com/example/plugin' } });\n");
    file_put_contents($root . DIRECTORY_SEPARATOR . 'service.toml', "# S3 example: bucket.s3-region.amazonaws.com\ns3_host = 'env(S3_HOST)'\n");
    file_put_contents($root . DIRECTORY_SEPARATOR . 'package.json', '{"name":"generic-next-key","config":{"next":"not-a-framework"},"dependencies":{"@aws-sdk/client-s3":"1.0.0","next-auth":"5.0.0"}}');
    file_put_contents($root . DIRECTORY_SEPARATOR . 'package-lock.json', '{"lockfileVersion":3,"packages":{"node_modules/aws-transitive":{"name":"aws-sdk"}}}');
    file_put_contents($root . DIRECTORY_SEPARATOR . '.playwright-cli' . DIRECTORY_SEPARATOR . 'page.yml', 'snapshot: generated browser evidence');
    $result = (new RepoScanner())->inspect($root, AnalysisProfile::QUICK);
    false_positive_assert(!in_array('.playwright-cli/page.yml', array_column($result['files'], 'path'), true), 'Generated Playwright artifacts must not enter repository analysis');
    false_positive_assert(!in_array('Next.js', $result['stack'], true), 'A generic next key must not prove Next.js');
    false_positive_assert(in_array('Auth.js', $result['stack'], true), 'A direct next-auth dependency must identify Auth.js');
    false_positive_assert(!in_array('Docker', $result['stack'], true), 'README Docker prose must not prove Docker use');
    $services = array_values(array_filter($result['symbol_graph']['symbols'], static fn (array $symbol): bool => ($symbol['type'] ?? '') === 'external_service'));
    false_positive_assert(array_column($services, 'name') === ['api.real-service.dev'], 'Only runtime endpoint configuration or network calls may prove external services; got ' . json_encode(array_column($services, 'name')));
    false_positive_assert(!in_array('AWS', array_column($services, 'name'), true), 'Manifest or lockfile package presence alone must not prove runtime AWS use');
    false_positive_assert(!in_array('Kubernetes', array_column($services, 'name'), true), 'A client API version field must not prove Kubernetes use');
    false_positive_assert(!in_array('github.com', array_column($services, 'name'), true), 'Application metadata URLs must not prove an external runtime service');
    $tables = array_values(array_filter($result['symbol_graph']['symbols'], static fn (array $symbol): bool => in_array($symbol['type'] ?? '', ['table','database_table'], true)));
    false_positive_assert(!in_array('the', array_column($tables, 'name'), true), 'SQL-like README prose must not create a database table');
    false_positive_assert(($result['symbol_graph']['features'] ?? []) === [], 'Docs, CSS, Blade prose, tests, and detector source must not prove product features');
    $runtimeFindings = array_values(array_filter($result['findings'], static fn (array $finding): bool => in_array($finding['path'] ?? '', ['README.md', 'styles.css', 'views/copy.blade.php', 'tests/fixture.php'], true) && in_array($finding['severity'] ?? '', ['attention', 'risk'], true)));
    false_positive_assert($runtimeFindings === [], 'Non-runtime prose must not become risk findings');
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    @rmdir($root);
}

echo "WTFCode V3 false-positive regression checks passed.\n";

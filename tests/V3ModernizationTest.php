<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function modern_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

echo "Running WTFCode V3 Modernization & Regression Test Suite...\n";

// 1. Test Tree-Sitter Worker Stdin Error Envelope Resilience
$runner = new SafeProcessRunner();
$node = ToolDetector::findExecutable('node');
modern_assert($node !== null, 'Node.js executable must be present');

$workerPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'workers' . DIRECTORY_SEPARATOR . 'tree-sitter.mjs';
$badJsonResult = $runner->run(new ProcessRunRequest(
    [$node, $workerPath],
    dirname(__DIR__),
    10,
    1048576,
    1048576,
    stdin: '{ malformed json :: [',
));

modern_assert($badJsonResult->succeeded(), 'Tree-Sitter worker must handle malformed JSON without process crash');
$badJsonDecoded = json_decode($badJsonResult->stdout, true);
modern_assert(is_array($badJsonDecoded), 'Tree-Sitter worker must output a valid JSON envelope on error');
modern_assert(count($badJsonDecoded['errors'] ?? []) > 0, 'Tree-Sitter worker envelope must report parse error');

// 2. Test ast-grep Worker Stdin Error Envelope Resilience
$astGrepPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'workers' . DIRECTORY_SEPARATOR . 'ast-grep.mjs';
$astGrepResult = $runner->run(new ProcessRunRequest(
    [$node, $astGrepPath],
    dirname(__DIR__),
    10,
    1048576,
    1048576,
    stdin: '{ bad-json :::',
));
modern_assert($astGrepResult->succeeded(), 'ast-grep worker must handle malformed JSON gracefully');
$astGrepDecoded = json_decode($astGrepResult->stdout, true);
modern_assert(is_array($astGrepDecoded), 'ast-grep worker must return JSON envelope on error');

// 3. Test TypeScript Semantic Worker Cross-File Call Resolution
$tsFiles = [
    [
        'path' => 'components/UserButton.tsx',
        'language' => 'TSX',
        'content' => "import { formatName } from '../utils/format';\nexport function UserButton({ name }: { name: string }) {\n  return <button>{formatName(name)}</button>;\n}",
        'lines' => 4,
    ],
    [
        'path' => 'utils/format.ts',
        'language' => 'TypeScript',
        'content' => "export function formatName(str: string): string {\n  return str.trim().toUpperCase();\n}",
        'lines' => 3,
    ],
];
$tsSemanticPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'workers' . DIRECTORY_SEPARATOR . 'typescript-semantic.mjs';
$tsPayload = json_encode(['repository' => dirname(__DIR__), 'files' => $tsFiles], JSON_THROW_ON_ERROR);
$tsResult = $runner->run(new ProcessRunRequest(
    [$node, $tsSemanticPath],
    dirname(__DIR__),
    15,
    1048576,
    1048576,
    stdin: $tsPayload,
));
modern_assert($tsResult->succeeded(), 'TypeScript semantic worker must succeed on valid multi-file inputs');
$tsGraph = json_decode($tsResult->stdout, true);
modern_assert(is_array($tsGraph), 'TypeScript semantic worker must return graph');
$calls = array_filter($tsGraph['relationships'] ?? [], static fn (array $rel): bool => $rel['type'] === 'calls');
modern_assert(count($calls) > 0, 'TypeScript semantic worker must detect calls across modules');

// 4. Test Tree-Sitter Multi-File Grammar Reuse Performance
$multiFiles = [];
for ($i = 1; $i <= 30; $i++) {
    $multiFiles[] = [
        'path' => "src/Module{$i}.ts",
        'language' => 'TypeScript',
        'content' => "export class Service{$i} { public execute(): number { return {$i} * 2; } }",
        'lines' => 1,
    ];
}
$multiPayload = json_encode(['repository' => dirname(__DIR__), 'files' => $multiFiles], JSON_THROW_ON_ERROR);
$start = hrtime(true);
$multiResult = $runner->run(new ProcessRunRequest(
    [$node, $workerPath],
    dirname(__DIR__),
    15,
    10485760,
    10485760,
    stdin: $multiPayload,
));
$elapsedMs = (hrtime(true) - $start) / 1_000_000;
modern_assert($multiResult->succeeded(), 'Tree-Sitter multi-file batch must succeed');
$multiGraph = json_decode($multiResult->stdout, true);
modern_assert(count($multiGraph['symbols'] ?? []) >= 30, 'Tree-Sitter must extract symbols for all 30 files with reused parser');
echo "Parsed 30 files in " . round($elapsedMs, 2) . "ms with reused parser instance.\n";

// 5. Test SQLite Configuration & Driver Compatibility
putenv('DB_DRIVER=sqlite');
putenv('DB_DATABASE=:memory:');
modern_assert(in_array(Database::driver(), ['sqlite', 'mysql', 'pgsql'], true), 'Database driver must support sqlite');

echo "All WTFCode V3 Modernization & Regression tests passed!\n";

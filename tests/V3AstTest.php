<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function ast_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function ast_file(string $root, string $path, string $language): array
{
    $content = file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));
    if ($content === false) throw new RuntimeException('Missing fixture file: ' . $path);
    return ['path' => $path, 'language' => $language, 'content' => $content, 'lines' => substr_count($content, "\n") + 1];
}

$phpRoot = __DIR__ . '/fixtures/v2/plain-php';
$phpRequest = new AnalysisRequest($phpRoot, [ast_file($phpRoot, 'src/AuthService.php', 'PHP')]);
$php = (new PhpAstAnalyzerProvider())->analyze($phpRequest);
ast_assert($php->status === AnalyzerResult::SUCCESS, 'PHP AST provider should parse the valid PHP fixture');
ast_assert(count(array_filter($php->graph['symbols'], static fn (array $symbol): bool => $symbol['type'] === 'class' && $symbol['name'] === 'AuthService')) === 1, 'PHP AST should extract classes');
ast_assert(count(array_filter($php->graph['symbols'], static fn (array $symbol): bool => $symbol['type'] === 'method' && $symbol['name'] === 'login')) === 1, 'PHP AST should extract methods');
ast_assert(count(array_filter($php->graph['relationships'], static fn (array $edge): bool => $edge['type'] === 'calls')) > 0, 'PHP AST should extract call expressions');

$tsRoot = __DIR__ . '/fixtures/v2/next-react';
$tsFiles = [
    ast_file($tsRoot, 'app/account/page.tsx', 'TypeScript'),
    ast_file($tsRoot, 'app/api/accounts/route.ts', 'TypeScript'),
    ast_file($tsRoot, 'components/AccountCard.tsx', 'TypeScript'),
    ast_file($tsRoot, 'hooks/useAccount.ts', 'TypeScript'),
];
$tsRequest = new AnalysisRequest($tsRoot, $tsFiles);
$semantic = (new TypeScriptSemanticAnalyzerProvider())->analyze($tsRequest);
ast_assert($semantic->status === AnalyzerResult::SUCCESS, 'TypeScript semantic worker should complete without executing project code');
ast_assert(count(array_filter($semantic->graph['symbols'], static fn (array $symbol): bool => $symbol['type'] === 'component' && $symbol['name'] === 'AccountCard')) === 1, 'Semantic worker should identify React components');
ast_assert(count(array_filter($semantic->graph['symbols'], static fn (array $symbol): bool => $symbol['type'] === 'hook' && $symbol['name'] === 'useAccount')) === 1, 'Semantic worker should identify hooks');
ast_assert(count(array_filter($semantic->graph['relationships'], static fn (array $edge): bool => $edge['type'] === 'calls' && $edge['target_key'] !== null)) > 0, 'Semantic worker should resolve at least one call to a declaration');

$tree = (new TreeSitterAnalyzerProvider())->analyze($tsRequest);
ast_assert(in_array($tree->status, [AnalyzerResult::SUCCESS, AnalyzerResult::PARTIAL], true), 'Tree-sitter worker should produce a usable result');
ast_assert(count($tree->graph['symbols']) >= count($tsFiles), 'Tree-sitter should create a module for every supported source file');
ast_assert(count(array_filter($tree->graph['symbols'], static fn (array $symbol): bool => $symbol['type'] === 'function')) > 0, 'Tree-sitter should extract syntax declarations');

$largeSource = implode("\n", array_map(static fn (int $index): string => 'export function alphaBound' . $index . '(): number { return ' . $index . '; }', range(1, 2100)));
$largeRequest = new AnalysisRequest($tsRoot, [['path' => 'src/generated-large.ts', 'language' => 'TypeScript', 'content' => $largeSource, 'lines' => 2100]]);
$bounded = (new TypeScriptSemanticAnalyzerProvider())->analyze($largeRequest);
ast_assert($bounded->status === AnalyzerResult::PARTIAL, 'A semantic worker that reaches its evidence budget must report partial analysis');
ast_assert(count($bounded->graph['symbols']) === 2000, 'Semantic worker output must be bounded before PHP decodes the graph');
ast_assert((int) ($bounded->graph['stats']['symbol_limit_reached'] ?? 0) === 1, 'Bounded semantic evidence must disclose the symbol limit');

$fused = (new AnalysisCoordinator())->analyze($tsRequest);
$confirmedImports = array_filter($fused['relationships'], static fn (array $edge): bool => $edge['type'] === 'imports' && count($edge['metadata']['engines'] ?? []) >= 2);
ast_assert($confirmedImports !== [], 'Independent syntax and semantic engines should fuse matching import evidence');
ast_assert(count($fused['engine_runs']) >= 5, 'Coordinator should report active and unavailable Phase 2 providers independently');

$ctags = (new CtagsAnalyzerProvider())->healthCheck();
ast_assert(in_array($ctags->status, ['ready', 'unavailable'], true), 'Ctags fallback health must be explicit on every machine');

echo "WTFCode V3 AST and semantic checks passed.\n";

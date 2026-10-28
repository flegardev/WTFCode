<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
$files = [
    ['path' => 'a-consumer.ts', 'content' => "import { helper as alias } from './z-helper'; export const result = alias(); external.helper(); class Child extends Parent {} class Parent {} interface Shape extends Base {} interface Base {}"],
    ['path' => 'z-helper.ts', 'content' => 'export function helper() { return true; }'],
];
$node = ToolDetector::findExecutable('node');
if ($node === null) throw new RuntimeException('Node is required for worker safety tests.');
$result = (new SafeProcessRunner())->run(new ProcessRunRequest([$node, 'workers/typescript-semantic.mjs'], dirname(__DIR__), 60, stdin: json_encode(['files' => $files], JSON_THROW_ON_ERROR)));
if (!$result->succeeded()) throw new RuntimeException('Classes with inheritance must not crash the semantic worker.');
$graph = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
$helper = array_values(array_filter($graph['symbols'], static fn (array $s): bool => $s['name'] === 'helper'))[0];
$alias = array_values(array_filter($graph['relationships'], static fn (array $e): bool => $e['type'] === 'calls' && $e['target_key'] === $helper['key']));
if (count($alias) !== 1) throw new RuntimeException('Only the bound aliased call may resolve to the helper; alphabetical file order and an unrelated same-name method must not create false certainty.');
$unresolved = array_values(array_filter($graph['relationships'], static fn (array $e): bool => $e['type'] === 'calls' && $e['external_name'] === 'external.helper'));
if (count($unresolved) !== 1 || $unresolved[0]['confidence'] === 'high') throw new RuntimeException('An unresolved external method must remain uncertain.');
echo "TypeScript worker inheritance, cross-file ordering, alias binding and false-certainty checks passed.\n";

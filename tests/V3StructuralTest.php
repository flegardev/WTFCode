<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function structural_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wtfcode-structural-' . bin2hex(random_bytes(6));
if (!mkdir($root, 0700, true) && !is_dir($root)) throw new RuntimeException('Could not create structural fixture.');

try {
    $source = <<<'TS'
import axios from 'axios';
import { writeFile } from 'node:fs/promises';

export async function submit(form: HTMLFormElement) {
  const endpoint = process.env.API_ENDPOINT;
  const response = await fetch(endpoint, { method: 'POST' });
  await axios.get('/health');
  await client.auth.signInWithPassword({ email: 'test@example.invalid', password: 'not-a-secret' });
  await db.users.create({ data: { email: 'test@example.invalid' } });
  await writeFile('report.txt', 'safe');
  return response;
}
TS;
    file_put_contents($root . DIRECTORY_SEPARATOR . 'flow.ts', $source);
    file_put_contents($root . DIRECTORY_SEPARATOR . 'README.md', 'Documentation example: eval(userInput) and process.env.NOT_RUNTIME');
    $request = new AnalysisRequest($root, [
        ['path' => 'flow.ts', 'language' => 'TypeScript', 'content' => $source, 'lines' => substr_count($source, "\n") + 1],
        ['path' => 'README.md', 'language' => 'Markdown', 'content' => 'Documentation example: eval(userInput)', 'lines' => 1],
    ]);

    $ast = (new AstGrepAnalyzerProvider())->analyze($request);
    structural_assert($ast->status === AnalyzerResult::SUCCESS, 'ast-grep NAPI worker should complete');
    $rules = array_column(array_column($ast->findings, 'evidence'), 'rule_id');
    structural_assert(in_array('wtfcode.http.fetch', $rules, true), 'ast-grep should detect fetch structurally');
    structural_assert(in_array('wtfcode.http.axios', $rules, true), 'ast-grep should detect Axios structurally');
    structural_assert(in_array('wtfcode.auth.supabase-login', $rules, true), 'ast-grep should detect the Supabase login boundary');
    structural_assert(in_array('wtfcode.database.prisma-create', $rules, true), 'ast-grep should detect ORM creates structurally');
    structural_assert(!in_array('README.md', array_column($ast->findings, 'path'), true), 'ast-grep should not reinterpret documentation as runtime code');
    structural_assert(isset($ast->findings[0]['evidence']['line_end'], $ast->findings[0]['evidence']['language']), 'Structural findings need normalized ranges and language');

    $rg = (new RipgrepAnalyzerProvider())->analyze($request);
    structural_assert(in_array($rg->status, [AnalyzerResult::SUCCESS, AnalyzerResult::UNAVAILABLE], true), 'ripgrep fallback must either run or report unavailable independently');
    structural_assert(!in_array('README.md', array_column($rg->findings, 'path'), true), 'Documentation text must not become runtime security evidence');

    structural_assert(EvidenceConfidence::label('typescript-semantic', 'high') === 'confirmed', 'Semantic compiler evidence should be confirmed');
    structural_assert(EvidenceConfidence::label('ast-grep', 'medium') === 'likely', 'Single structural patterns should be likely, not confirmed');
    structural_assert(EvidenceConfidence::label('ripgrep', 'low') === 'heuristic', 'Text fallback must stay heuristic');
    structural_assert(EvidenceConfidence::label('php-parser', 'high', 2) === 'confirmed', 'Independent strong evidence should remain confirmed');
} finally {
    foreach (['flow.ts', 'README.md'] as $file) @unlink($root . DIRECTORY_SEPARATOR . $file);
    @rmdir($root);
}

echo "WTFCode V3 structural intelligence checks passed.\n";

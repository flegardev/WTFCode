<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . '. Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$findingSummary = Project::deduplicateFindings([
    ['finding_type' => 'database_operation', 'title' => 'Database operation', 'file_path' => 'lib/db.ts'],
    ['finding_type' => 'database_operation', 'title' => 'Database operation', 'file_path' => 'lib/db.ts'],
    ['finding_type' => 'authentication_boundary', 'title' => 'Authentication boundary', 'file_path' => 'auth.ts'],
], 12);
assert_same(2, count($findingSummary), 'First-screen findings should collapse repeated type, title, and file combinations');

assert_same('https://github.com/openai/openai-quickstart-node.git', RepositoryImporter::normalizeGithubUrl('https://github.com/openai/openai-quickstart-node'), 'Public GitHub URLs should normalize to a clone URL');
assert_same(null, RepositoryImporter::normalizeGithubUrl('git@github.com:openai/openai-quickstart-node.git'), 'SSH URLs should be rejected');
assert_same(null, RepositoryImporter::normalizeGithubUrl('https://example.com/openai/openai-quickstart-node'), 'Non-GitHub hosts should be rejected');
assert_same(null, RepositoryImporter::normalizeGithubUrl('https://github.com/openai/openai-quickstart-node?download=1'), 'Repository URLs with query strings should be rejected');

$explanation = ExplanationService::explainFile(
    ['plain_summary' => 'This file protects requests.', 'role_name' => 'middleware', 'line_count' => 24],
    [['target_path' => './auth']],
    [['path' => 'app/page.tsx'], ['path' => 'app/settings/page.tsx']]
);
assert_same(true, str_contains($explanation['human'], '2 other files directly depend on it'), 'Blast-radius explanations should state direct dependents');

$promptEvidence = PromptSafetyService::selectEvidence(
    ['config/auth.php', 'app/Models/User.php', 'public/index.php'],
    [
        ['path' => '.github/workflows/tests.yml', 'role_name' => 'source'],
        ['path' => 'public/index.php', 'role_name' => 'route'],
        ['path' => 'app/Models/User.php', 'role_name' => 'data model'],
        ['path' => 'config/auth.php', 'role_name' => 'authentication'],
        ['path' => 'README.md', 'role_name' => 'source'],
    ]
);
assert_same(['config/auth.php', 'app/Models/User.php', 'public/index.php', '.github/workflows/tests.yml', 'README.md'], array_column($promptEvidence, 'path'), 'Prompt evidence should prioritize architecture-backed auth and data files over alphabetical metadata');

$fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wtfcode-scan-' . bin2hex(random_bytes(4));
mkdir($fixture . DIRECTORY_SEPARATOR . 'src', 0700, true);
file_put_contents($fixture . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'entry.ts', "import { helper } from './helper';\nexport function run() { return helper(); }\n");
file_put_contents($fixture . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'helper.ts', "export const helper = () => 'ok';\n");
$inspection = (new RepoScanner())->inspect($fixture);
assert_same(2, count($inspection['files']), 'Scanner inspection should only retain readable fixture files');
$entry = array_values(array_filter($inspection['files'], static fn (array $file): bool => $file['path'] === 'src/entry.ts'))[0];
assert_same(['./helper'], $entry['imports'], 'Scanner should extract supported relative imports');
assert_same(true, in_array('run', $entry['symbols'], true), 'Scanner should retain a small symbol index without storing source in the database');
unlink($fixture . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'entry.ts');
unlink($fixture . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'helper.ts');
rmdir($fixture . DIRECTORY_SEPARATOR . 'src');
rmdir($fixture);

$technologyFixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wtfcode-stack-' . bin2hex(random_bytes(4));
mkdir($technologyFixture . DIRECTORY_SEPARATOR . 'public', 0700, true);
mkdir($technologyFixture . DIRECTORY_SEPARATOR . 'src', 0700, true);
mkdir($technologyFixture . DIRECTORY_SEPARATOR . 'database', 0700, true);
file_put_contents($technologyFixture . DIRECTORY_SEPARATOR . 'README.md', 'WTFCode can inspect Next.js, FastAPI, Supabase, and Vercel projects.');
file_put_contents($technologyFixture . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php', "<?php\nrequire_once '../bootstrap.php';\n");
file_put_contents($technologyFixture . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Auth.php', "<?php\nsession_start();\n");
file_put_contents($technologyFixture . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Database.php', "<?php\nnew PDO('mysql:host=localhost');\n");
file_put_contents($technologyFixture . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema.sql', 'CREATE TABLE users (password_hash VARCHAR(255));');
$technologyInspection = (new RepoScanner())->inspect($technologyFixture);
assert_same(['PHP', 'Database'], $technologyInspection['stack'], 'Documentation must not create false framework detections');
assert_same(['api', 'auth', 'database'], array_column($technologyInspection['nodes'], 'key'), 'Architecture evidence should retain only systems supported by project files');
assert_same(['src/Auth.php'], $technologyInspection['nodes'][1]['evidence'], 'Authentication architecture evidence should identify the concrete authentication file');
unlink($technologyFixture . DIRECTORY_SEPARATOR . 'README.md');
unlink($technologyFixture . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php');
unlink($technologyFixture . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Auth.php');
unlink($technologyFixture . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Database.php');
unlink($technologyFixture . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema.sql');
rmdir($technologyFixture . DIRECTORY_SEPARATOR . 'public');
rmdir($technologyFixture . DIRECTORY_SEPARATOR . 'src');
rmdir($technologyFixture . DIRECTORY_SEPARATOR . 'database');
rmdir($technologyFixture);

$plainPhpFixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wtfcode-plain-php-' . bin2hex(random_bytes(4));
mkdir($plainPhpFixture, 0700, true);
file_put_contents($plainPhpFixture . DIRECTORY_SEPARATOR . 'admin.php', "<?php\nnew PDO('mysql:host=localhost');\n");
$plainPhpInspection = (new RepoScanner())->inspect($plainPhpFixture);
assert_same(['PHP', 'Database'], $plainPhpInspection['stack'], 'Plain PHP projects without a public directory should still be detected');
assert_same(['api', 'database'], array_column($plainPhpInspection['nodes'], 'key'), 'Plain PHP projects should retain a server and data architecture map');
unlink($plainPhpFixture . DIRECTORY_SEPARATOR . 'admin.php');
rmdir($plainPhpFixture);

$frameworkFixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wtfcode-frameworks-' . bin2hex(random_bytes(4));
mkdir($frameworkFixture . DIRECTORY_SEPARATOR . 'django', 0700, true);
mkdir($frameworkFixture . DIRECTORY_SEPARATOR . 'vue' . DIRECTORY_SEPARATOR . 'src', 0700, true);
file_put_contents($frameworkFixture . DIRECTORY_SEPARATOR . 'django' . DIRECTORY_SEPARATOR . 'manage.py', "from django.core.management import execute_from_command_line\n");
file_put_contents($frameworkFixture . DIRECTORY_SEPARATOR . 'django' . DIRECTORY_SEPARATOR . 'pagination.py', "payload = {'next': 'cursor'}\n");
file_put_contents($frameworkFixture . DIRECTORY_SEPARATOR . 'vue' . DIRECTORY_SEPARATOR . 'package.json', '{"dependencies":{"vue":"^3.0.0"}}');
file_put_contents($frameworkFixture . DIRECTORY_SEPARATOR . 'vue' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'App.vue', '<template><main>Hello</main></template>');
$djangoInspection = (new RepoScanner())->inspect($frameworkFixture . DIRECTORY_SEPARATOR . 'django');
$vueInspection = (new RepoScanner())->inspect($frameworkFixture . DIRECTORY_SEPARATOR . 'vue');
assert_same(['Django'], $djangoInspection['stack'], 'A generic next data key must not create a Next.js framework detection');
assert_same(['api'], array_column($djangoInspection['nodes'], 'key'), 'Django should expose a server architecture node');
assert_same(['Vue'], $vueInspection['stack'], 'Vue package and component evidence should produce a Vue framework detection');
assert_same(['frontend'], array_column($vueInspection['nodes'], 'key'), 'Vue should expose a frontend architecture node');
unlink($frameworkFixture . DIRECTORY_SEPARATOR . 'django' . DIRECTORY_SEPARATOR . 'manage.py');
unlink($frameworkFixture . DIRECTORY_SEPARATOR . 'django' . DIRECTORY_SEPARATOR . 'pagination.py');
unlink($frameworkFixture . DIRECTORY_SEPARATOR . 'vue' . DIRECTORY_SEPARATOR . 'package.json');
unlink($frameworkFixture . DIRECTORY_SEPARATOR . 'vue' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'App.vue');
rmdir($frameworkFixture . DIRECTORY_SEPARATOR . 'django');
rmdir($frameworkFixture . DIRECTORY_SEPARATOR . 'vue' . DIRECTORY_SEPARATOR . 'src');
rmdir($frameworkFixture . DIRECTORY_SEPARATOR . 'vue');
rmdir($frameworkFixture);

$alphaFrameworkFixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wtfcode-alpha-frameworks-' . bin2hex(random_bytes(4));
$cases = [
    'flask' => ['app.py' => "from flask import Flask\napp = Flask(__name__)\n@app.get('/health')\ndef health(): return 'ok'\n", 'expected' => 'Flask'],
    'fastapi-lookalike' => ['app.py' => "class App:\n    def get(self, path): return path\napp = App()\n@app.get('/not-fastapi')\ndef handler(): return 'ok'\n", 'expected' => null],
    'nest' => ['package.json' => '{"dependencies":{"@nestjs/core":"^11.0.0"}}', 'main.ts' => "import { NestFactory } from '@nestjs/core';\n", 'expected' => 'NestJS'],
    'svelte' => ['package.json' => '{"dependencies":{"@sveltejs/kit":"^2.0.0"}}', 'src/routes/+page.svelte' => '<h1>Hello</h1>', 'expected' => 'SvelteKit'],
    'nuxt' => ['package.json' => '{"dependencies":{"nuxt":"^4.0.0"}}', 'nuxt.config.ts' => 'export default defineNuxtConfig({})', 'expected' => 'Nuxt'],
    'laravel' => ['composer.json' => '{"require":{"laravel/framework":"^12.0"}}', 'artisan' => '<?php', 'expected' => 'Laravel'],
    'firebase' => ['package.json' => '{"dependencies":{"firebase":"^12.0.0"}}', 'src/app.ts' => "import { initializeApp } from 'firebase/app';\ninitializeApp({});", 'expected' => 'Firebase'],
    'prisma' => ['package.json' => '{"dependencies":{"@prisma/client":"^6.0.0"}}', 'src/app.ts' => 'export const app = true;', 'expected' => 'Prisma'],
    'drizzle' => ['package.json' => '{"dependencies":{"drizzle-orm":"^0.40.0"}}', 'src/app.ts' => 'export const app = true;', 'expected' => 'Drizzle'],
];
foreach ($cases as $name => $case) {
    $directory = $alphaFrameworkFixture . DIRECTORY_SEPARATOR . $name;
    mkdir($directory, 0700, true);
    foreach ($case as $path => $content) {
        if ($path === 'expected') continue;
        $target = $directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
        file_put_contents($target, $content);
    }
    $detected = (new RepoScanner())->inspect($directory)['stack'];
    if ($case['expected'] === null) assert_same(false, in_array('FastAPI', $detected, true), 'Generic Python route decorators must not prove FastAPI');
    else assert_same(true, in_array($case['expected'], $detected, true), $case['expected'] . ' should be detected from explicit framework evidence');
    if ($name === 'flask') assert_same(1, count((new RepoScanner())->inspect($directory)['symbol_graph']['routes']), 'Flask route decorators should become explicit routes');
}
$cleanup = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($alphaFrameworkFixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($cleanup as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
rmdir($alphaFrameworkFixture);

echo "WTFCode unit checks passed.\n";

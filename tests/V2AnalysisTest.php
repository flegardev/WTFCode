<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function v2_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function v2_has_symbol(array $graph, string $type, string $name): bool
{
    return count(array_filter($graph['symbols'], static fn (array $symbol): bool => $symbol['type'] === $type && $symbol['name'] === $name)) > 0;
}

function v2_has_route(array $graph, string $method, string $path, string $framework = ''): bool
{
    return count(array_filter($graph['routes'], static fn (array $route): bool => $route['method'] === $method && $route['route_path'] === $path && ($framework === '' || $route['framework'] === $framework))) > 0;
}

function v2_has_relationship(array $graph, string $type, ?string $targetName = null): bool
{
    return count(array_filter($graph['relationships'], static fn (array $edge): bool => $edge['type'] === $type && ($targetName === null || $edge['target_name'] === $targetName || $edge['external_name'] === $targetName))) > 0;
}

$root = __DIR__ . '/fixtures/v2';
$marker = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wtfcode-imported-code-executed';
if (is_file($marker)) unlink($marker);

$plain = (new RepoScanner())->inspect($root . '/plain-php');
v2_assert(v2_has_symbol($plain['symbol_graph'], 'class', 'AuthService'), 'Plain PHP should extract classes');
v2_assert(v2_has_symbol($plain['symbol_graph'], 'method', 'login'), 'Plain PHP should extract methods');
v2_assert(v2_has_symbol($plain['symbol_graph'], 'table', 'users'), 'Plain PHP and SQL should extract table evidence');
v2_assert(v2_has_symbol($plain['symbol_graph'], 'table', 'audit_log'), 'PHP query builders should extract table evidence');
v2_assert(!v2_has_symbol($plain['symbol_graph'], 'table', 'documentation'), 'Prose comments must not become table evidence');
v2_assert(!v2_has_symbol($plain['symbol_graph'], 'external_service', 'example.com'), 'Documentation URLs must not become service boundaries');
v2_assert(v2_has_symbol($plain['symbol_graph'], 'environment_variable', 'DATABASE_URL'), 'Plain PHP should extract environment reads without values');
v2_assert(v2_has_route($plain['symbol_graph'], 'ANY', '/login.php', 'Plain PHP'), 'Public PHP entry files should become routes');
v2_assert(v2_has_relationship($plain['symbol_graph'], 'instantiates'), 'Plain PHP should extract instantiation edges');
v2_assert(!is_file($marker), 'Imported PHP source must never execute during analysis');

$laravel = (new RepoScanner())->inspect($root . '/laravel');
v2_assert(v2_has_route($laravel['symbol_graph'], 'GET', '/account', 'Laravel'), 'Laravel routes should retain method and path');
v2_assert(v2_has_symbol($laravel['symbol_graph'], 'controller', 'AccountController'), 'Laravel controllers should be tagged');
v2_assert(v2_has_symbol($laravel['symbol_graph'], 'model', 'Account'), 'Laravel Eloquent models should be tagged');
v2_assert(v2_has_symbol($laravel['symbol_graph'], 'table', 'accounts'), 'Laravel SQL migrations should expose tables');
v2_assert(v2_has_relationship($laravel['symbol_graph'], 'foreign_key_to', 'customers') === false, 'Unrelated table names must not leak between fixture scans');

$next = (new RepoScanner())->inspect($root . '/next-react');
v2_assert(v2_has_route($next['symbol_graph'], 'GET', '/account', 'Next.js'), 'Next.js page routes should be indexed');
v2_assert(v2_has_route($next['symbol_graph'], 'GET', '/api/accounts', 'Next.js'), 'Next.js GET handlers should be indexed');
v2_assert(v2_has_route($next['symbol_graph'], 'POST', '/api/accounts', 'Next.js'), 'Next.js POST handlers should be indexed');
v2_assert(v2_has_symbol($next['symbol_graph'], 'component', 'AccountCard'), 'React components should be extracted');
v2_assert(v2_has_symbol($next['symbol_graph'], 'hook', 'useAccount'), 'React hooks should be extracted');
v2_assert(v2_has_relationship($next['symbol_graph'], 'renders', 'AccountCard'), 'JSX render edges should resolve to components');

$fastApi = (new RepoScanner())->inspect($root . '/fastapi');
v2_assert(v2_has_route($fastApi['symbol_graph'], 'GET', '/accounts/{account_id}', 'FastAPI'), 'FastAPI decorators should produce routes');
v2_assert(v2_has_symbol($fastApi['symbol_graph'], 'schema', 'Account'), 'Pydantic models should be tagged as schemas');
v2_assert(v2_has_symbol($fastApi['symbol_graph'], 'environment_variable', 'AUTH_MODE'), 'Python environment reads should be extracted');

$django = (new RepoScanner())->inspect($root . '/django');
v2_assert(v2_has_route($django['symbol_graph'], 'ANY', '/accounts/<int:account_id>/', 'Django'), 'Django URL patterns should produce routes');
v2_assert(v2_has_symbol($django['symbol_graph'], 'model', 'Account'), 'Django models should be tagged');
v2_assert(v2_has_symbol($django['symbol_graph'], 'function', 'account_detail'), 'Django views should retain function symbols');

$sql = (new RepoScanner())->inspect($root . '/sql');
v2_assert(v2_has_symbol($sql['symbol_graph'], 'table', 'customers'), 'SQL should extract customer table definitions');
v2_assert(v2_has_symbol($sql['symbol_graph'], 'table', 'orders'), 'SQL should extract order table definitions');
v2_assert(v2_has_symbol($sql['symbol_graph'], 'column', 'customer_id'), 'SQL should extract columns under their table');
v2_assert(v2_has_symbol($sql['symbol_graph'], 'view', 'customer_totals'), 'SQL should extract views');
v2_assert(v2_has_relationship($sql['symbol_graph'], 'foreign_key_to', 'customers'), 'SQL should resolve foreign-key relationships');

foreach ([$plain, $laravel, $next, $fastApi, $django, $sql] as $inspection) {
    v2_assert(($inspection['symbol_graph']['stats']['symbols'] ?? 0) > 0, 'Every V2 fixture should produce normalized symbols');
    foreach ($inspection['symbol_graph']['symbols'] as $symbol) v2_assert(!array_key_exists('content', $symbol), 'Normalized symbol rows must not contain source contents');
    foreach ($inspection['symbol_graph']['relationships'] as $edge) {
        v2_assert(in_array($edge['confidence'], ['high', 'medium', 'low'], true), 'Every relationship needs an explicit confidence');
        v2_assert($edge['evidence_path'] !== '' && $edge['line_start'] >= 1, 'Every relationship needs file and line evidence');
    }
}

$boundedGraph = new SymbolGraph();
for ($index = 0; $index < 8050; $index++) {
    $boundedGraph->addSymbol(['path' => 'bounded.php', 'language' => 'PHP', 'type' => 'function', 'name' => 'bounded_' . $index, 'qualified_name' => 'bounded_' . $index, 'start_line' => $index + 1]);
}
$boundedStats = $boundedGraph->toArray()['stats'];
v2_assert($boundedStats['symbols'] === 8000 && $boundedStats['symbol_limit_reached'] === 1, 'Large repositories must truncate the normalized graph before exhausting PHP memory');

$started = microtime(true);
$dogfood = (new RepoScanner())->inspect(dirname(__DIR__));
$elapsed = microtime(true) - $started;
v2_assert(in_array('PHP', $dogfood['stack'], true), 'WTFCode should detect its own PHP stack');
v2_assert(v2_has_symbol($dogfood['symbol_graph'], 'class', 'RepoScanner'), 'WTFCode should find its own scanner class');
v2_assert(v2_has_symbol($dogfood['symbol_graph'], 'class', 'FeatureTracer'), 'WTFCode should find its own feature tracer class');
v2_assert(count($dogfood['symbol_graph']['routes']) >= 10, 'WTFCode should map its own public PHP entry routes');
v2_assert($elapsed < 15, 'WTFCode dogfood analysis should complete within 15 seconds on the local fixture');

echo 'WTFCode V2 analyzer checks passed in ' . number_format($elapsed, 2) . "s.\n";

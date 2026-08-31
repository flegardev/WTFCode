<?php

declare(strict_types=1);

namespace WTFCode\Application;

use BlastRadiusService;
use FeatureTracer;
use WTFCode\Repository\TestEvidenceRepository;

final class ChangeImpactService
{
    public function __construct(private readonly TestEvidenceRepository $tests = new TestEvidenceRepository())
    {
    }

    /** @return array<string, mixed> */
    public function forTarget(int $projectId, string $target): array
    {
        $trace = FeatureTracer::trace($projectId, $target);
        $paths = array_values(array_unique(array_filter(array_column($trace['files'], 'path'), 'is_string')));
        $symbols = array_values(array_filter($trace['symbols'], static fn (array $symbol): bool => isset($symbol['id'])));
        $impact = $this->expandBlastRadius($projectId, $symbols, $paths, $trace['entry_points'], $trace['tables']);
        $impact['trace'] = $trace;
        $impact['target'] = $target;
        return $impact;
    }

    /** @param array<string, mixed> $diff */
    public function forDiff(int $projectId, array $diff): array
    {
        $paths = [];
        foreach ($diff['groups'] ?? [] as $changes) {
            foreach ($changes as $change) {
                if (is_string($change['path'] ?? null)) $paths[] = $change['path'];
                if (is_string($change['old_path'] ?? null)) $paths[] = $change['old_path'];
            }
        }
        $symbols = is_array($diff['impact']['symbols'] ?? null) ? $diff['impact']['symbols'] : [];
        $routes = is_array($diff['impact']['routes'] ?? null) ? $diff['impact']['routes'] : [];
        $tables = is_array($diff['impact']['tables'] ?? null) ? $diff['impact']['tables'] : [];
        $impact = $this->expandBlastRadius($projectId, $symbols, $paths, $routes, $tables);
        $impact['graph_context'] = $diff['graph_context'] ?? ['state' => 'unknown'];
        return $impact;
    }

    /**
     * Pure risk classification used by the application service and unit tests.
     *
     * @param array<int, string> $paths
     * @param array<int, array<string, mixed>> $routes
     * @param array<int, array<string, mixed>> $tables
     * @param array<int, array<string, mixed>> $services
     * @param array<int, string> $blastRisks
     * @return array{level:string,reasons:array<int,string>}
     */
    public static function classify(array $paths, array $routes, array $tables, array $services, array $blastRisks): array
    {
        $reasons = [];
        $sensitive = array_values(array_filter($paths, static function (string $path): bool {
            $normalized = str_replace('\\', '/', $path);
            $normalized = preg_replace('/([a-z0-9])([A-Z])/', '$1/$2', $normalized) ?? $normalized;

            return preg_match(
                '#(?:^|[/._-])(?:auth|authentication|authorization|login|session|middleware|guard|migrations?|schema|database|config|configuration|\.env)(?:[/._-]|$)#i',
                $normalized,
            ) === 1;
        }));
        if ($sensitive !== []) $reasons[] = count($sensitive) . ' sensitive path' . (count($sensitive) === 1 ? '' : 's') . ' changed or affected';
        if ($tables !== []) $reasons[] = count($tables) . ' data boundar' . (count($tables) === 1 ? 'y' : 'ies') . ' affected';
        if ($services !== []) $reasons[] = count($services) . ' external service boundar' . (count($services) === 1 ? 'y' : 'ies') . ' affected';
        if ($routes !== []) $reasons[] = count($routes) . ' route' . (count($routes) === 1 ? '' : 's') . ' affected';
        if (in_array('high', $blastRisks, true)) $reasons[] = 'A matched symbol has a high static blast radius';

        $high = $sensitive !== [] || $tables !== [] || $services !== [] || in_array('high', $blastRisks, true) || count($routes) >= 5;
        $medium = !$high && (count($paths) >= 5 || $routes !== [] || in_array('medium', $blastRisks, true));
        return [
            'level' => $high ? 'high' : ($medium ? 'medium' : ($paths === [] ? 'unknown' : 'low')),
            'reasons' => $reasons === [] ? ['No high-signal static risk boundary was detected. Runtime behavior can still extend the impact.'] : $reasons,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $symbols
     * @param array<int, string> $seedPaths
     * @param array<int, array<string, mixed>> $seedRoutes
     * @param array<int, array<string, mixed>> $seedTables
     * @return array<string, mixed>
     */
    private function expandBlastRadius(int $projectId, array $symbols, array $seedPaths, array $seedRoutes, array $seedTables): array
    {
        $files = [];
        foreach ($seedPaths as $path) if (is_string($path) && $path !== '') $files[$path] = ['path' => $path, 'depth' => 0];
        $routes = [];
        foreach ($seedRoutes as $route) {
            $label = (string) ($route['label'] ?? (($route['http_method'] ?? '') . ' ' . ($route['route_path'] ?? '')));
            if (trim($label) !== '') $routes[$label] = $route + ['label' => trim($label)];
        }
        $tables = [];
        foreach ($seedTables as $table) {
            $key = (string) ($table['id'] ?? $table['name'] ?? $table['path'] ?? count($tables));
            $tables[$key] = $table;
        }
        $services = [];
        $direct = [];
        $transitive = [];
        $chains = [];
        $blastRisks = [];
        $symbolNames = [];

        foreach (array_slice($symbols, 0, 20) as $symbol) {
            $symbolId = filter_var($symbol['id'] ?? null, FILTER_VALIDATE_INT);
            if ($symbolId === false || $symbolId === null) continue;
            $symbolNames[] = (string) ($symbol['name'] ?? 'symbol');
            $blast = BlastRadiusService::forSymbol($projectId, (int) $symbolId);
            $blastRisks[] = (string) ($blast['risk'] ?? 'unknown');
            foreach ($blast['files'] ?? [] as $file) if (is_string($file['path'] ?? null)) $files[$file['path']] = $file;
            foreach ($blast['routes'] ?? [] as $route) {
                $label = trim((string) ($route['http_method'] ?? '') . ' ' . (string) ($route['route_path'] ?? ''));
                if ($label !== '') $routes[$label] = $route + ['label' => $label];
            }
            foreach ($blast['tables'] ?? [] as $table) $tables[(string) ($table['id'] ?? $table['name'] ?? count($tables))] = $table;
            foreach ($blast['direct'] ?? [] as $item) $direct[(string) ($item['id'] ?? $item['name'])] = $item;
            foreach ($blast['transitive'] ?? [] as $item) $transitive[(string) ($item['id'] ?? $item['name'])] = $item;

            $first = array_values($blast['direct'] ?? [])[0] ?? null;
            $second = array_values($blast['transitive'] ?? [])[0] ?? null;
            $chain = [['name' => (string) ($symbol['name'] ?? 'Selected symbol'), 'type' => (string) ($symbol['symbol_type'] ?? $symbol['type'] ?? 'symbol')]];
            if ($first !== null) $chain[] = ['name' => (string) $first['name'], 'type' => (string) ($first['relationship'] ?? 'dependent')];
            if ($second !== null) $chain[] = ['name' => (string) $second['name'], 'type' => (string) ($second['relationship'] ?? 'transitive dependent')];
            if (count($chain) > 1) $chains[] = $chain;
        }

        foreach ($symbols as $symbol) {
            if (($symbol['symbol_type'] ?? $symbol['type'] ?? '') === 'external_service') $services[(string) ($symbol['id'] ?? $symbol['name'])] = $symbol;
        }

        $pathValues = array_keys($files);
        $routeValues = array_values($routes);
        $tableValues = array_values($tables);
        $serviceValues = array_values($services);
        $risk = self::classify($pathValues, $routeValues, $tableValues, $serviceValues, $blastRisks);
        $likelyTests = $this->tests->likelyForChange($projectId, $pathValues, $symbolNames);
        $recommendations = $this->recommendations($risk['level'], $pathValues, $routeValues, $tableValues, $serviceValues, $likelyTests);

        return [
            'risk' => $risk,
            'files' => array_slice(array_values($files), 0, 150),
            'symbols' => array_slice($symbols, 0, 150),
            'routes' => array_slice($routeValues, 0, 100),
            'tables' => array_slice($tableValues, 0, 100),
            'services' => array_slice($serviceValues, 0, 100),
            'direct' => array_slice(array_values($direct), 0, 100),
            'transitive' => array_slice(array_values($transitive), 0, 100),
            'chains' => array_slice($chains, 0, 6),
            'likely_tests' => $likelyTests,
            'test_coverage_unknown' => $likelyTests === [],
            'recommendations' => $recommendations,
            'limitations' => 'This is deterministic static evidence. Dynamic dispatch, generated code, runtime configuration, and infrastructure outside the repository can extend the impact.',
        ];
    }

    /** @return array<int, string> */
    private function recommendations(string $risk, array $paths, array $routes, array $tables, array $services, array $tests): array
    {
        $items = [];
        if ($tests === []) $items[] = 'Add a regression test around the selected behavior before changing its contract.';
        else $items[] = 'Run the matched tests first, then the repository-wide suite.';
        if ($routes !== []) $items[] = 'Exercise affected request flows, including authentication and authorization failures.';
        if ($tables !== []) $items[] = 'Apply schema changes to disposable data and verify backward compatibility and rollback.';
        if ($services !== []) $items[] = 'Test external-service failure, timeout, and retry behavior without exposing credentials.';
        if ($risk === 'high') $items[] = 'Review every direct consumer before merge and confirm the highest-risk path manually.';
        if ($paths !== []) $items[] = 'Rescan after the edit and compare the resulting evidence before merge.';
        return array_values(array_unique($items));
    }
}

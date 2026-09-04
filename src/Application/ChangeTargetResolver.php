<?php

declare(strict_types=1);

namespace WTFCode\Application;

use Project;
use SymbolRepository;

/**
 * Resolves a user-selected change target into bounded graph seed evidence.
 */
final class ChangeTargetResolver
{
    /**
     * @param array<string, mixed> $trace
     * @return array{symbols:array<int,array<string,mixed>>,paths:array<int,string>,routes:array<int,array<string,mixed>>,tables:array<int,array<string,mixed>>,services:array<int,array<string,mixed>>}
     */
    public function resolve(int $projectId, string $targetType, string $target, array $trace): array
    {
        if ($targetType === 'feature') {
            return [
                'symbols' => array_values(array_filter($trace['symbols'] ?? [], static fn(array $symbol): bool => isset($symbol['id']))),
                'paths' => array_values(array_unique(array_filter(array_column($trace['files'] ?? [], 'path'), 'is_string'))),
                'routes' => array_values(array_filter($trace['entry_points'] ?? [], 'is_array')),
                'tables' => array_values(array_filter($trace['tables'] ?? [], 'is_array')),
                'services' => array_values(array_filter($trace['services'] ?? [], 'is_array')),
            ];
        }

        if ($targetType === 'file') {
            return $this->resolveFile($projectId, $target);
        }

        if ($targetType === 'route') {
            return $this->resolveRoute($projectId, $target, $trace);
        }

        if ($targetType === 'table') {
            $matches = self::preferExactRows(SymbolRepository::tables($projectId), $target);

            return [
                'symbols' => array_slice($matches, 0, 20),
                'paths' => array_values(array_unique(array_filter(array_column($matches, 'path'), 'is_string'))),
                'routes' => [],
                'tables' => array_slice($matches, 0, 20),
                'services' => [],
            ];
        }

        return $this->resolveSymbol($projectId, $target);
    }

    /**
     * @return array{symbols:array<int,array<string,mixed>>,paths:array<int,string>,routes:array<int,array<string,mixed>>,tables:array<int,array<string,mixed>>,services:array<int,array<string,mixed>>}
     */
    private function resolveFile(int $projectId, string $target): array
    {
        $normalizedTarget = str_replace('\\', '/', $target);
        $files = Project::files($projectId, $normalizedTarget, 30);
        $exact = array_values(array_filter($files, static fn(array $file): bool => strcasecmp(str_replace('\\', '/', (string) ($file['path'] ?? '')), $normalizedTarget) === 0));
        $files = $exact !== [] ? $exact : $files;
        $paths = array_values(array_unique(array_filter(array_column($files, 'path'), 'is_string')));
        $context = SymbolRepository::impactForPaths($projectId, $paths);
        $symbols = [];
        foreach (array_values(array_filter($context['symbols'] ?? [], 'is_array')) as $symbol) {
            if (isset($symbol['id'])) {
                $symbols[(string) $symbol['id']] = $symbol;
            }
        }
        foreach (array_values(array_filter($context['routes'] ?? [], 'is_array')) as $route) {
            $handlerId = filter_var($route['handler_symbol_id'] ?? null, FILTER_VALIDATE_INT);
            if ($handlerId === false || $handlerId === null) {
                continue;
            }
            $symbol = SymbolRepository::symbol($projectId, (int) $handlerId);
            if ($symbol !== null) {
                $symbols[(string) $handlerId] = $symbol;
            }
        }

        return [
            'symbols' => array_values($symbols),
            'paths' => $paths,
            'routes' => array_values(array_filter($context['routes'] ?? [], 'is_array')),
            'tables' => array_values(array_filter($context['tables'] ?? [], 'is_array')),
            'services' => [],
        ];
    }

    /**
     * @param array<string, mixed> $trace
     * @return array{symbols:array<int,array<string,mixed>>,paths:array<int,string>,routes:array<int,array<string,mixed>>,tables:array<int,array<string,mixed>>,services:array<int,array<string,mixed>>}
     */
    private function resolveRoute(int $projectId, string $target, array $trace): array
    {
        [$method, $path] = self::splitRouteTarget($target);
        $routes = SymbolRepository::routes($projectId, $path);
        if ($method !== null) {
            $routes = array_values(array_filter($routes, static fn(array $route): bool => strtoupper((string) ($route['http_method'] ?? '')) === $method));
        }
        $exact = array_values(array_filter($routes, static fn(array $route): bool => strcasecmp((string) ($route['route_path'] ?? ''), $path) === 0));
        $routes = array_slice($exact !== [] ? $exact : $routes, 0, 20);
        $symbols = [];
        foreach ($routes as $route) {
            $handlerId = filter_var($route['handler_symbol_id'] ?? null, FILTER_VALIDATE_INT);
            if ($handlerId === false || $handlerId === null) {
                continue;
            }
            $symbol = SymbolRepository::symbol($projectId, (int) $handlerId);
            if ($symbol !== null) {
                $symbols[(int) $handlerId] = $symbol;
            }
        }

        return [
            'symbols' => array_values($symbols),
            'paths' => array_values(array_unique(array_filter(array_column($routes, 'path'), 'is_string'))),
            'routes' => $routes,
            'tables' => array_values(array_filter($trace['tables'] ?? [], 'is_array')),
            'services' => array_values(array_filter($trace['services'] ?? [], 'is_array')),
        ];
    }

    /**
     * @return array{symbols:array<int,array<string,mixed>>,paths:array<int,string>,routes:array<int,array<string,mixed>>,tables:array<int,array<string,mixed>>,services:array<int,array<string,mixed>>}
     */
    private function resolveSymbol(int $projectId, string $target): array
    {
        $matches = self::preferExactRows(SymbolRepository::search($projectId, $target, 50), $target);
        $symbols = [];
        foreach (array_slice($matches, 0, 20) as $match) {
            if (isset($match['id'])) {
                $symbols[(string) $match['id']] = $match;
            }
            $fileId = filter_var($match['file_id'] ?? null, FILTER_VALIDATE_INT);
            if ($fileId === false || $fileId === null) {
                continue;
            }
            $fileSymbols = SymbolRepository::fileSymbols($projectId, (int) $fileId);
            $descendantIds = isset($match['id']) ? [(string) $match['id'] => true] : [];
            $changed = true;
            while ($changed) {
                $changed = false;
                foreach ($fileSymbols as $fileSymbol) {
                    $symbolId = isset($fileSymbol['id']) ? (string) $fileSymbol['id'] : '';
                    $parentId = isset($fileSymbol['parent_symbol_id']) ? (string) $fileSymbol['parent_symbol_id'] : '';
                    $isModuleAnchor = ($fileSymbol['symbol_type'] ?? '') === 'module';
                    $isDescendant = $parentId !== '' && isset($descendantIds[$parentId]);
                    if ($symbolId === '' || (!$isModuleAnchor && !$isDescendant)) {
                        continue;
                    }
                    if (!isset($symbols[$symbolId])) {
                        $symbols[$symbolId] = $fileSymbol + ['path' => $match['path'] ?? ''];
                    }
                    if ($isDescendant && !isset($descendantIds[$symbolId])) {
                        $descendantIds[$symbolId] = true;
                        $changed = true;
                    }
                }
            }
        }

        return [
            'symbols' => array_values($symbols),
            'paths' => array_values(array_unique(array_filter(array_column($matches, 'path'), 'is_string'))),
            'routes' => [],
            'tables' => [],
            'services' => [],
        ];
    }

    /** @return array{0:?string,1:string} */
    public static function splitRouteTarget(string $target): array
    {
        $target = trim($target);
        if (preg_match('/^([A-Za-z]+)\s+(.+)$/', $target, $match) === 1) {
            return [strtoupper($match[1]), trim($match[2])];
        }

        return [null, $target];
    }

    /**
     * Prefer exact name/qualified-name/path matches while retaining fuzzy
     * fallback behavior for incomplete user input.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function preferExactRows(array $rows, string $target): array
    {
        $target = strtolower(str_replace('\\', '/', trim($target)));
        $matches = array_values(array_filter($rows, static function (array $row) use ($target): bool {
            foreach (['name', 'qualified_name', 'path'] as $key) {
                if (str_contains(strtolower(str_replace('\\', '/', (string) ($row[$key] ?? ''))), $target)) {
                    return true;
                }
            }
            return false;
        }));
        $exact = array_values(array_filter($matches, static function (array $row) use ($target): bool {
            foreach (['name', 'qualified_name', 'path'] as $key) {
                if (strtolower(str_replace('\\', '/', (string) ($row[$key] ?? ''))) === $target) {
                    return true;
                }
            }
            return false;
        }));

        return $exact !== [] ? $exact : $matches;
    }
}

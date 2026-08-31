<?php

declare(strict_types=1);

final class SymbolGraph
{
    private const MAX_SYMBOLS = 8000;
    private const MAX_RELATIONSHIPS = 16000;
    private const MAX_ROUTES = 4000;
    /** @var array<string, array<string, mixed>> */
    private array $symbols = [];
    /** @var array<string, array<string, mixed>> */
    private array $relationships = [];
    /** @var array<string, array<string, mixed>> */
    private array $routes = [];
    private bool $symbolLimitReached = false;
    private bool $relationshipLimitReached = false;
    private bool $routeLimitReached = false;

    public function addModule(array $file): string
    {
        return $this->addSymbol([
            'path' => $file['path'],
            'language' => $file['language'],
            'type' => 'module',
            'name' => basename((string) $file['path']),
            'qualified_name' => (string) $file['path'],
            'start_line' => 1,
            'end_line' => max(1, (int) ($file['lines'] ?? 1)),
            'confidence' => 'high',
        ]);
    }

    public function addSymbol(array $symbol): string
    {
        $path = (string) ($symbol['path'] ?? '');
        $type = (string) ($symbol['type'] ?? 'unknown');
        $name = trim((string) ($symbol['name'] ?? ''));
        $qualified = trim((string) ($symbol['qualified_name'] ?? $name));
        $line = max(1, (int) ($symbol['start_line'] ?? 1));
        if ($path === '' || $name === '' || $qualified === '') {
            throw new InvalidArgumentException('A symbol needs a file path, type, name, and qualified name.');
        }
        $key = hash('sha256', $path . '|' . $type . '|' . $qualified . '|' . $line);
        if (!isset($this->symbols[$key]) && count($this->symbols) >= self::MAX_SYMBOLS) {
            $this->symbolLimitReached = true;
            return $key;
        }
        $this->symbols[$key] = [
            'key' => $key,
            'path' => $path,
            'language' => (string) ($symbol['language'] ?? 'Unknown'),
            'type' => $type,
            'name' => $name,
            'qualified_name' => $qualified,
            'parent_key' => $symbol['parent_key'] ?? null,
            'signature' => isset($symbol['signature']) ? trim((string) $symbol['signature']) : null,
            'visibility' => in_array($symbol['visibility'] ?? '', ['public', 'protected', 'private', 'package'], true) ? $symbol['visibility'] : 'unknown',
            'exported' => (bool) ($symbol['exported'] ?? false),
            'start_line' => $line,
            'end_line' => max($line, (int) ($symbol['end_line'] ?? $line)),
            'confidence' => self::confidence($symbol['confidence'] ?? 'medium'),
            'metadata' => is_array($symbol['metadata'] ?? null) ? $symbol['metadata'] : [],
        ];
        return $key;
    }

    public function addRelationship(array $relationship): void
    {
        $path = (string) ($relationship['evidence_path'] ?? '');
        $type = trim((string) ($relationship['type'] ?? ''));
        $targetKey = $relationship['target_key'] ?? null;
        $external = trim((string) ($relationship['external_name'] ?? $relationship['target_name'] ?? ''));
        if ($path === '' || $type === '' || ($targetKey === null && $external === '')) return;
        $sourceKey = $relationship['source_key'] ?? null;
        if (($sourceKey !== null && !isset($this->symbols[$sourceKey])) || ($targetKey !== null && !isset($this->symbols[$targetKey]))) return;
        $line = max(1, (int) ($relationship['line'] ?? 1));
        $key = hash('sha256', implode('|', [(string) ($relationship['source_key'] ?? ''), (string) $targetKey, $external, $type, $path, (string) $line]));
        if (!isset($this->relationships[$key]) && count($this->relationships) >= self::MAX_RELATIONSHIPS) {
            $this->relationshipLimitReached = true;
            return;
        }
        $this->relationships[$key] = [
            'source_key' => $relationship['source_key'] ?? null,
            'target_key' => $targetKey,
            'external_name' => $external === '' ? null : $external,
            'target_name' => trim((string) ($relationship['target_name'] ?? '')),
            'type' => $type,
            'confidence' => self::confidence($relationship['confidence'] ?? 'medium'),
            'evidence_path' => $path,
            'line_start' => $line,
            'line_end' => max($line, (int) ($relationship['line_end'] ?? $line)),
            'excerpt' => isset($relationship['excerpt']) ? trim((string) $relationship['excerpt']) : null,
            'metadata' => is_array($relationship['metadata'] ?? null) ? $relationship['metadata'] : [],
        ];
    }

    public function addRoute(array $route): string
    {
        $path = (string) ($route['path'] ?? '');
        $routePath = trim((string) ($route['route_path'] ?? ''));
        $method = strtoupper(trim((string) ($route['method'] ?? 'ANY')));
        $line = max(1, (int) ($route['line'] ?? 1));
        if ($path === '' || $routePath === '') throw new InvalidArgumentException('A route needs an evidence path and route path.');
        $key = hash('sha256', $path . '|' . $method . '|' . $routePath . '|' . $line);
        if (!isset($this->routes[$key]) && count($this->routes) >= self::MAX_ROUTES) {
            $this->routeLimitReached = true;
            return $key;
        }
        $this->routes[$key] = [
            'key' => $key,
            'path' => $path,
            'handler_key' => $route['handler_key'] ?? null,
            'framework' => (string) ($route['framework'] ?? 'Unknown'),
            'method' => $method,
            'route_path' => $routePath,
            'name' => isset($route['name']) ? trim((string) $route['name']) : null,
            'middleware' => array_values(array_unique(array_filter($route['middleware'] ?? [], 'is_string'))),
            'confidence' => self::confidence($route['confidence'] ?? 'high'),
            'line' => $line,
            'metadata' => is_array($route['metadata'] ?? null) ? $route['metadata'] : [],
        ];
        return $key;
    }

    public function findSymbolKey(string $path, string $name, array $types = []): ?string
    {
        $matches = [];
        foreach ($this->symbols as $key => $symbol) {
            if ($symbol['path'] !== $path || ($symbol['name'] !== $name && $symbol['qualified_name'] !== $name)) continue;
            if ($types !== [] && !in_array($symbol['type'], $types, true)) continue;
            $matches[] = $key;
        }
        return count($matches) === 1 ? $matches[0] : ($matches[0] ?? null);
    }

    /** @return array<int, array<string, mixed>> */
    public function symbolsForFile(string $path): array
    {
        return array_values(array_filter($this->symbols, static fn (array $symbol): bool => $symbol['path'] === $path));
    }

    public function tagSymbol(string $key, array $metadata, ?string $type = null): void
    {
        if (!isset($this->symbols[$key])) return;
        $this->symbols[$key]['metadata'] = array_merge($this->symbols[$key]['metadata'], $metadata);
        if ($type !== null) $this->symbols[$key]['type'] = $type;
    }

    public function finalize(): void
    {
        $byQualified = [];
        $byName = [];
        foreach ($this->symbols as $key => $symbol) {
            $byQualified[strtolower($symbol['qualified_name'])][] = $key;
            $byName[strtolower($symbol['name'])][] = $key;
        }
        foreach ($this->relationships as &$relationship) {
            if ($relationship['target_key'] !== null || $relationship['target_name'] === '') continue;
            $needle = strtolower($relationship['target_name']);
            $parts = preg_split('/[\\\\.]/', $needle) ?: [$needle];
            $shortName = end($parts) ?: $needle;
            $candidates = $byQualified[$needle] ?? $byName[$needle] ?? $byName[$shortName] ?? [];
            if (count($candidates) === 1) {
                $relationship['target_key'] = $candidates[0];
                $relationship['external_name'] = null;
            }
        }
        unset($relationship);
    }

    /** @return array{symbols: array<int, array<string, mixed>>, relationships: array<int, array<string, mixed>>, routes: array<int, array<string, mixed>>, stats: array<string, int>} */
    public function toArray(): array
    {
        return [
            'symbols' => array_values($this->symbols),
            'relationships' => array_values($this->relationships),
            'routes' => array_values($this->routes),
            'stats' => [
                'symbols' => count($this->symbols),
                'relationships' => count($this->relationships),
                'routes' => count($this->routes),
                'files_with_symbols' => count(array_unique(array_column($this->symbols, 'path'))),
                'symbol_limit_reached' => (int) $this->symbolLimitReached,
                'relationship_limit_reached' => (int) $this->relationshipLimitReached,
                'route_limit_reached' => (int) $this->routeLimitReached,
            ],
        ];
    }

    private static function confidence(mixed $confidence): string
    {
        return in_array($confidence, ['high', 'medium', 'low'], true) ? $confidence : 'medium';
    }
}

<?php

declare(strict_types=1);

final class LaravelAdapter extends AbstractFrameworkAdapter
{
    public function enrich(array $files, SymbolGraph $graph): void
    {
        $isLaravel = $this->hasDependency($files, 'composer.json', 'laravel/framework')
            || count(array_filter($files, static fn (array $file): bool => preg_match('#^routes/(?:web|api|console)\.php$#i', (string) $file['path']) === 1)) > 0;
        if (!$isLaravel) return;

        foreach ($files as $file) {
            if (($file['language'] ?? '') !== 'PHP') continue;
            $path = (string) $file['path'];
            foreach ($graph->symbolsForFile($path) as $symbol) {
                if ($symbol['type'] === 'class' && (str_contains((string) $file['content'], 'extends Model') || preg_match('#(^|/)Models?/#i', $path))) $graph->tagSymbol($symbol['key'], ['framework' => 'Laravel', 'framework_role' => 'eloquent_model'], 'model');
                if ($symbol['type'] === 'class' && preg_match('#(^|/)Http/Controllers?/#i', $path)) $graph->tagSymbol($symbol['key'], ['framework' => 'Laravel', 'framework_role' => 'controller'], 'controller');
                if ($symbol['type'] === 'method' && $symbol['name'] === 'handle' && preg_match('#(^|/)Http/Middleware/#i', $path)) $graph->tagSymbol($symbol['key'], ['framework' => 'Laravel', 'framework_role' => 'middleware'], 'middleware');
            }
            foreach ($this->lines($file) as $offset => $line) {
                if (!preg_match('/Route::(get|post|put|patch|delete|options|any|match|resource|apiResource)\s*\(\s*[\'\"]([^\'\"]+)[\'\"]\s*,?\s*(.*)$/i', $line, $match)) continue;
                $method = strtoupper($match[1]);
                $tail = $match[3];
                $handlerKey = null;
                $handlerName = null;
                if (preg_match('/([A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)::class\s*,\s*[\'\"]([A-Za-z_][A-Za-z0-9_]*)[\'\"]/', $tail, $handler)) {
                    $handlerName = $handler[2];
                    foreach ($files as $candidate) {
                        if (($candidate['language'] ?? '') !== 'PHP') continue;
                        $handlerKey = $graph->findSymbolKey((string) $candidate['path'], $handlerName, ['method']);
                        if ($handlerKey !== null) break;
                    }
                } elseif (preg_match('/[\'\"]([A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)@([A-Za-z_][A-Za-z0-9_]*)[\'\"]/', $tail, $handler)) {
                    $handlerName = $handler[2];
                }
                $middleware = [];
                if (preg_match('/->middleware\s*\(\s*(?:\[([^]]+)\]|[\'\"]([^\'\"]+)[\'\"])/', $line, $middlewareMatch)) {
                    $raw = $middlewareMatch[1] ?: $middlewareMatch[2];
                    preg_match_all('/[\'\"]([^\'\"]+)[\'\"]/', $raw, $middlewareItems);
                    $middleware = $middlewareItems[1] ?: [$raw];
                }
                $name = preg_match('/->name\s*\(\s*[\'\"]([^\'\"]+)[\'\"]/', $line, $nameMatch) ? $nameMatch[1] : null;
                $graph->addRoute(['path' => $path, 'handler_key' => $handlerKey, 'framework' => 'Laravel', 'method' => $method, 'route_path' => $this->normalizedWebPath($match[2]), 'name' => $name, 'middleware' => $middleware, 'confidence' => 'high', 'line' => $offset + 1, 'metadata' => ['handler_name' => $handlerName]]);
                $source = $graph->findSymbolKey($path, basename($path), ['module']);
                if ($handlerKey !== null) $graph->addRelationship(['source_key' => $source, 'target_key' => $handlerKey, 'type' => 'routes_to', 'confidence' => 'high', 'evidence_path' => $path, 'line' => $offset + 1, 'excerpt' => $this->excerpt($line)]);
            }
        }
    }
}

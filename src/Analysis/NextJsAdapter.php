<?php

declare(strict_types=1);

final class NextJsAdapter extends AbstractFrameworkAdapter
{
    public function enrich(array $files, SymbolGraph $graph): void
    {
        if (!$this->hasDependency($files, 'package.json', 'next')) return;
        foreach ($files as $file) {
            $path = str_replace('\\', '/', (string) $file['path']);
            if (!in_array($file['language'] ?? '', ['JavaScript', 'TypeScript'], true)) continue;
            $routePath = $this->filesystemRoute($path);
            if ($routePath === null) continue;
            $isHandlerFile = preg_match('#/(?:route|api/[^/]+)\.(?:jsx?|tsx?)$#i', $path) === 1;
            $methods = [];
            if ($isHandlerFile) {
                foreach ($graph->symbolsForFile($path) as $symbol) {
                    if (in_array(strtoupper($symbol['name']), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], true)) {
                        $methods[] = ['method' => strtoupper($symbol['name']), 'handler' => $symbol['key']];
                        $graph->tagSymbol($symbol['key'], ['framework' => 'Next.js', 'framework_role' => 'route_handler'], 'route_handler');
                    }
                }
            }
            if ($methods === []) {
                $handler = null;
                foreach ($graph->symbolsForFile($path) as $symbol) if (in_array($symbol['type'], ['component', 'function'], true)) { $handler = $symbol['key']; break; }
                $methods[] = ['method' => 'GET', 'handler' => $handler];
            }
            foreach ($methods as $method) $graph->addRoute(['path' => $path, 'handler_key' => $method['handler'], 'framework' => 'Next.js', 'method' => $method['method'], 'route_path' => $routePath, 'line' => 1, 'confidence' => 'high', 'metadata' => ['source' => 'file-system route']]);
            if (preg_match('/[\'\"]use server[\'\"]\s*;?/', (string) $file['content'])) {
                foreach ($graph->symbolsForFile($path) as $symbol) if (in_array($symbol['type'], ['function', 'component'], true)) $graph->tagSymbol($symbol['key'], ['framework' => 'Next.js', 'framework_role' => 'server_action'], 'server_action');
            }
        }
    }

    private function filesystemRoute(string $path): ?string
    {
        if (preg_match('#^app/(.+/)?(?:page|route)\.(?:jsx?|tsx?)$#i', $path, $match)) $route = $match[1] ?? '';
        elseif (preg_match('#^pages/(?:api/)?(.+)\.(?:jsx?|tsx?)$#i', $path, $match)) $route = $match[1];
        else return null;
        $route = preg_replace('#\([^/]+\)/#', '', $route) ?? $route;
        $route = preg_replace('/\[\.\.\.([A-Za-z0-9_]+)\]/', ':$1*', $route) ?? $route;
        $route = preg_replace('/\[([A-Za-z0-9_]+)\]/', ':$1', $route) ?? $route;
        $route = preg_replace('#/(?:index)$#i', '', $route) ?? $route;
        return $this->normalizedWebPath(rtrim($route, '/'));
    }
}

<?php

declare(strict_types=1);

final class FlaskAdapter extends AbstractFrameworkAdapter
{
    public function enrich(array $files, SymbolGraph $graph): void
    {
        $isFlask = false;
        foreach ($files as $file) {
            if (($file['language'] ?? '') === 'Python' && preg_match('/\b(?:from\s+flask\s+import|import\s+flask\b|Flask\s*\()/', (string) $file['content'])) { $isFlask = true; break; }
        }
        if (!$isFlask) return;
        foreach ($files as $file) {
            if (($file['language'] ?? '') !== 'Python' || !RuntimeEvidencePolicy::isRuntimePath((string) $file['path'])) continue;
            $path = (string) $file['path'];
            $lines = $this->lines($file);
            foreach ($lines as $offset => $line) {
                if (!preg_match('/@(?:app|blueprint|bp)\.route\s*\(\s*[\'\"]([^\'\"]+)[\'\"]([^)]*)\)|@(?:app|blueprint|bp)\.(get|post|put|patch|delete)\s*\(\s*[\'\"]([^\'\"]+)[\'\"]/i', $line, $route)) continue;
                $routePath = $route[1] !== '' ? $route[1] : ($route[4] ?? '/');
                $method = isset($route[3]) && $route[3] !== '' ? strtoupper($route[3]) : 'ANY';
                if ($method === 'ANY' && preg_match('/methods\s*=\s*\[\s*[\'\"]([A-Z]+)/i', $route[2] ?? '', $methodMatch)) $method = strtoupper($methodMatch[1]);
                $handlerKey = null;
                for ($next = $offset + 1; $next < min(count($lines), $offset + 5); $next++) {
                    if (!preg_match('/^\s*(?:async\s+)?def\s+([A-Za-z_][A-Za-z0-9_]*)/', $lines[$next], $handler)) continue;
                    $handlerKey = $graph->findSymbolKey($path, $handler[1], ['function']);
                    if ($handlerKey !== null) $graph->tagSymbol($handlerKey, ['framework' => 'Flask', 'framework_role' => 'route_handler'], 'route_handler');
                    break;
                }
                $graph->addRoute(['path' => $path, 'handler_key' => $handlerKey, 'framework' => 'Flask', 'method' => $method, 'route_path' => $this->normalizedWebPath($routePath), 'line' => $offset + 1, 'confidence' => 'high']);
            }
        }
    }
}

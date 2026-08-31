<?php

declare(strict_types=1);

final class FastApiAdapter extends AbstractFrameworkAdapter
{
    public function enrich(array $files, SymbolGraph $graph): void
    {
        $isFastApi = false;
        foreach ($files as $file) if (($file['language'] ?? '') === 'Python' && preg_match('/\b(?:from\s+fastapi\s+import|FastAPI\s*\()/', (string) $file['content'])) { $isFastApi = true; break; }
        if (!$isFastApi) return;
        foreach ($files as $file) {
            if (($file['language'] ?? '') !== 'Python') continue;
            $path = (string) $file['path'];
            $lines = $this->lines($file);
            foreach ($lines as $offset => $line) {
                if (!preg_match('/@(?:app|router)\.(get|post|put|patch|delete|options|head)\s*\(\s*[\'\"]([^\'\"]+)[\'\"]([^)]*)\)/i', $line, $route)) continue;
                $handlerKey = null;
                for ($next = $offset + 1; $next < min(count($lines), $offset + 5); $next++) {
                    if (preg_match('/^\s*(?:async\s+)?def\s+([A-Za-z_][A-Za-z0-9_]*)/', $lines[$next], $handler)) {
                        $handlerKey = $graph->findSymbolKey($path, $handler[1], ['function']);
                        if ($handlerKey !== null) $graph->tagSymbol($handlerKey, ['framework' => 'FastAPI', 'framework_role' => 'route_handler'], 'route_handler');
                        break;
                    }
                }
                $graph->addRoute(['path' => $path, 'handler_key' => $handlerKey, 'framework' => 'FastAPI', 'method' => $route[1], 'route_path' => $this->normalizedWebPath($route[2]), 'line' => $offset + 1, 'confidence' => 'high', 'metadata' => ['dependencies' => str_contains($route[3], 'Depends(') ? 'declared' : 'none visible']]);
            }
            foreach ($graph->symbolsForFile($path) as $symbol) {
                if ($symbol['type'] !== 'class') continue;
                if (preg_match('/class\s+' . preg_quote($symbol['name'], '/') . '\s*\([^)]*(?:BaseModel|SQLModel)/', (string) $file['content'])) $graph->tagSymbol($symbol['key'], ['framework' => 'Pydantic', 'framework_role' => 'schema'], 'schema');
            }
        }
    }
}

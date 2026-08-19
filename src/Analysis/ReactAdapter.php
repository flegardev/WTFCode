<?php

declare(strict_types=1);

final class ReactAdapter extends AbstractFrameworkAdapter
{
    public function enrich(array $files, SymbolGraph $graph): void
    {
        if (!$this->hasDependency($files, 'package.json', 'react')) return;
        foreach ($files as $file) {
            if (!in_array($file['language'] ?? '', ['JavaScript', 'TypeScript'], true)) continue;
            $path = (string) $file['path'];
            foreach ($graph->symbolsForFile($path) as $symbol) {
                if ($symbol['type'] === 'component') $graph->tagSymbol($symbol['key'], ['framework' => 'React', 'framework_role' => 'component']);
                if ($symbol['type'] === 'hook') $graph->tagSymbol($symbol['key'], ['framework' => 'React', 'framework_role' => 'hook']);
            }
            foreach ($this->lines($file) as $offset => $line) {
                if (!preg_match('/<Route\b[^>]*\bpath=[\'\"]([^\'\"]+)[\'\"][^>]*>/i', $line, $route)) continue;
                $handlerKey = null;
                if (preg_match('/\belement=\{?\s*<([A-Z][A-Za-z0-9_]*)/', $line, $element)) $handlerKey = $graph->findSymbolKey($path, $element[1], ['component']);
                $graph->addRoute(['path' => $path, 'handler_key' => $handlerKey, 'framework' => 'React Router', 'method' => 'GET', 'route_path' => $this->normalizedWebPath($route[1]), 'line' => $offset + 1, 'confidence' => 'high']);
            }
        }
    }
}

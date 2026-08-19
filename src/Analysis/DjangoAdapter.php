<?php

declare(strict_types=1);

final class DjangoAdapter extends AbstractFrameworkAdapter
{
    public function enrich(array $files, SymbolGraph $graph): void
    {
        $isDjango = false;
        foreach ($files as $file) if (($file['language'] ?? '') === 'Python' && preg_match('/\b(?:django\.|from\s+django|DJANGO_SETTINGS_MODULE)/', (string) $file['content'])) { $isDjango = true; break; }
        if (!$isDjango) return;
        foreach ($files as $file) {
            if (($file['language'] ?? '') !== 'Python') continue;
            $path = (string) $file['path'];
            foreach ($this->lines($file) as $offset => $line) {
                if (!preg_match('/\b(path|re_path)\s*\(\s*[rR]?[\'\"]([^\'\"]*)[\'\"]\s*,\s*([A-Za-z_][A-Za-z0-9_.]*)/', $line, $route)) continue;
                $handlerName = basename(str_replace('.', '/', $route[3]));
                $handlerKey = null;
                foreach ($files as $candidate) {
                    if (($candidate['language'] ?? '') !== 'Python') continue;
                    $handlerKey = $graph->findSymbolKey((string) $candidate['path'], $handlerName, ['function', 'class']);
                    if ($handlerKey !== null) break;
                }
                $name = preg_match('/\bname\s*=\s*[\'\"]([^\'\"]+)[\'\"]/', $line, $named) ? $named[1] : null;
                $graph->addRoute(['path' => $path, 'handler_key' => $handlerKey, 'framework' => 'Django', 'method' => 'ANY', 'route_path' => $this->normalizedWebPath($route[2]), 'name' => $name, 'line' => $offset + 1, 'confidence' => 'high', 'metadata' => ['handler_name' => $route[3]]]);
            }
            foreach ($graph->symbolsForFile($path) as $symbol) {
                if ($symbol['type'] === 'class' && preg_match('/class\s+' . preg_quote($symbol['name'], '/') . '\s*\([^)]*models\.Model/', (string) $file['content'])) $graph->tagSymbol($symbol['key'], ['framework' => 'Django', 'framework_role' => 'model'], 'model');
            }
        }
    }
}

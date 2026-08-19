<?php

declare(strict_types=1);

final class VueAdapter extends AbstractFrameworkAdapter
{
    public function enrich(array $files, SymbolGraph $graph): void
    {
        if (!$this->hasDependency($files, 'package.json', 'vue')) return;
        foreach ($files as $file) {
            $path = (string) $file['path'];
            if (($file['language'] ?? '') === 'Vue') {
                $existing = array_values(array_filter($graph->symbolsForFile($path), static fn (array $symbol): bool => $symbol['type'] === 'component'));
                if ($existing === []) $graph->addSymbol(['path' => $path, 'language' => 'Vue', 'type' => 'component', 'name' => pathinfo($path, PATHINFO_FILENAME), 'qualified_name' => $path . '#' . pathinfo($path, PATHINFO_FILENAME), 'start_line' => 1, 'end_line' => (int) ($file['lines'] ?? 1), 'exported' => true, 'confidence' => 'high', 'metadata' => ['framework' => 'Vue', 'single_file_component' => true]]);
            }
            foreach ($this->lines($file) as $offset => $line) {
                if (preg_match('/defineStore\s*\(\s*[\'\"]([^\'\"]+)[\'\"]/', $line, $store)) $graph->addSymbol(['path' => $path, 'language' => (string) $file['language'], 'type' => 'store', 'name' => $store[1], 'qualified_name' => 'pinia:' . $store[1], 'start_line' => $offset + 1, 'confidence' => 'high', 'metadata' => ['framework' => 'Pinia']]);
                if (preg_match('/\bpath\s*:\s*[\'\"]([^\'\"]+)[\'\"]/', $line, $route)) $graph->addRoute(['path' => $path, 'framework' => 'Vue Router', 'method' => 'GET', 'route_path' => $this->normalizedWebPath($route[1]), 'line' => $offset + 1, 'confidence' => 'medium']);
            }
        }
    }
}

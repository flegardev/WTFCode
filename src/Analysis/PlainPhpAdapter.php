<?php

declare(strict_types=1);

final class PlainPhpAdapter extends AbstractFrameworkAdapter
{
    public function enrich(array $files, SymbolGraph $graph): void
    {
        if ($this->hasDependency($files, 'composer.json', 'laravel/framework')) return;
        foreach ($files as $file) {
            $path = str_replace('\\', '/', (string) $file['path']);
            if (($file['language'] ?? '') !== 'PHP' || preg_match('#^public/([^/]+)\.php$#i', $path, $match) !== 1) continue;
            $routePath = strtolower($match[1]) === 'index' ? '/' : '/' . $match[1] . '.php';
            $content = (string) $file['content'];
            $method = preg_match('/\$_POST|is_post\s*\(/', $content) ? 'ANY' : 'GET';
            $moduleKey = $graph->findSymbolKey($path, basename($path), ['module']);
            $graph->addRoute(['path' => $path, 'handler_key' => $moduleKey, 'framework' => 'Plain PHP', 'method' => $method, 'route_path' => $routePath, 'line' => 1, 'confidence' => 'high', 'metadata' => ['source' => 'public entry file']]);
        }
    }
}

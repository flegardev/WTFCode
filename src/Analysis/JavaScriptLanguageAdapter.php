<?php

declare(strict_types=1);

final class JavaScriptLanguageAdapter extends AbstractLanguageAdapter
{
    public function supports(array $file): bool
    {
        return in_array($file['language'] ?? '', ['JavaScript', 'TypeScript', 'Vue', 'Svelte'], true);
    }

    public function extract(array $file, SymbolGraph $graph): void
    {
        $path = (string) $file['path'];
        $language = (string) $file['language'];
        $moduleKey = $this->moduleKey($file, $graph);
        $activeSymbol = $moduleKey;
        foreach ($this->lines($file) as $offset => $line) {
            $lineNumber = $offset + 1;
            $trimmed = trim($line);
            $exported = preg_match('/^export\s+/', $trimmed) === 1;
            if (preg_match('/^(?:export\s+)?(?:default\s+)?class\s+([A-Za-z_$][A-Za-z0-9_$]*)/', $trimmed, $match)) {
                $activeSymbol = $graph->addSymbol(['path' => $path, 'language' => $language, 'type' => 'class', 'name' => $match[1], 'qualified_name' => $path . '#' . $match[1], 'start_line' => $lineNumber, 'signature' => $this->excerpt($trimmed), 'visibility' => 'public', 'exported' => $exported, 'confidence' => 'high']);
                if (preg_match('/\bextends\s+([A-Za-z_$][A-Za-z0-9_$.]*)/', $trimmed, $parent)) $graph->addRelationship(['source_key' => $activeSymbol, 'target_name' => $parent[1], 'external_name' => $parent[1], 'type' => 'extends', 'confidence' => 'high', 'evidence_path' => $path, 'line' => $lineNumber, 'excerpt' => $this->excerpt($line)]);
            } elseif (preg_match('/^(?:export\s+)?(?:default\s+)?(?:async\s+)?function\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*\(([^)]*)\)/', $trimmed, $match)) {
                $type = str_starts_with($match[1], 'use') ? 'hook' : (ctype_upper($match[1][0]) ? 'component' : 'function');
                $activeSymbol = $graph->addSymbol(['path' => $path, 'language' => $language, 'type' => $type, 'name' => $match[1], 'qualified_name' => $path . '#' . $match[1], 'start_line' => $lineNumber, 'signature' => $this->excerpt($trimmed), 'visibility' => 'public', 'exported' => $exported, 'confidence' => 'high', 'metadata' => ['parameters' => trim($match[2])]]);
            } elseif (preg_match('/^(?:export\s+)?(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*(?::[^=]+)?=\s*(?:async\s*)?(?:\([^)]*\)|[A-Za-z_$][A-Za-z0-9_$]*)\s*=>/', $trimmed, $match)) {
                $type = str_starts_with($match[1], 'use') ? 'hook' : (ctype_upper($match[1][0]) ? 'component' : 'function');
                $activeSymbol = $graph->addSymbol(['path' => $path, 'language' => $language, 'type' => $type, 'name' => $match[1], 'qualified_name' => $path . '#' . $match[1], 'start_line' => $lineNumber, 'signature' => $this->excerpt($trimmed), 'visibility' => 'package', 'exported' => $exported, 'confidence' => 'high']);
            } elseif (preg_match('/^(?:export\s+)?(?:interface|type|enum)\s+([A-Za-z_$][A-Za-z0-9_$]*)/', $trimmed, $match)) {
                $activeSymbol = $graph->addSymbol(['path' => $path, 'language' => $language, 'type' => str_contains($trimmed, 'interface ') ? 'interface' : (str_contains($trimmed, 'enum ') ? 'enum' : 'type'), 'name' => $match[1], 'qualified_name' => $path . '#' . $match[1], 'start_line' => $lineNumber, 'signature' => $this->excerpt($trimmed), 'exported' => $exported, 'confidence' => 'high']);
            }

            if (preg_match('/^import\s+.+?\s+from\s+[\'\"]([^\'\"]+)[\'\"]|^import\s+[\'\"]([^\'\"]+)[\'\"]|require\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', $trimmed, $match)) {
                $target = $match[1] ?: ($match[2] ?: ($match[3] ?? ''));
                if ($target !== '') $graph->addRelationship(['source_key' => $moduleKey, 'target_name' => $target, 'external_name' => $target, 'type' => 'imports', 'confidence' => 'high', 'evidence_path' => $path, 'line' => $lineNumber, 'excerpt' => $this->excerpt($line)]);
            }
            if (preg_match_all('/\b([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/', $line, $matches)) {
                foreach ($matches[1] as $target) {
                    if (in_array($target, ['if', 'for', 'while', 'switch', 'catch', 'function'], true) || str_contains($trimmed, 'function ' . $target)) continue;
                    $graph->addRelationship(['source_key' => $activeSymbol, 'target_name' => $target, 'external_name' => $target, 'type' => 'calls', 'confidence' => 'low', 'evidence_path' => $path, 'line' => $lineNumber, 'excerpt' => $this->excerpt($line)]);
                }
            }
            if (preg_match_all('/<([A-Z][A-Za-z0-9_$.]*)\b/', $line, $matches)) {
                foreach ($matches[1] as $target) $graph->addRelationship(['source_key' => $activeSymbol, 'target_name' => $target, 'external_name' => $target, 'type' => 'renders', 'confidence' => 'high', 'evidence_path' => $path, 'line' => $lineNumber, 'excerpt' => $this->excerpt($line)]);
            }
            if (preg_match_all('/(?:process\.env\.|import\.meta\.env\.)([A-Z][A-Z0-9_]+)/', $line, $matches)) {
                foreach ($matches[1] as $name) {
                    $envKey = $graph->addSymbol(['path' => $path, 'language' => $language, 'type' => 'environment_variable', 'name' => $name, 'qualified_name' => 'env:' . $name, 'start_line' => $lineNumber, 'confidence' => 'high']);
                    $graph->addRelationship(['source_key' => $activeSymbol, 'target_key' => $envKey, 'type' => 'reads_env', 'confidence' => 'high', 'evidence_path' => $path, 'line' => $lineNumber, 'excerpt' => $this->excerpt($line)]);
                }
            }
            if (preg_match_all('#https?://[^\s\'\"`<>]+#i', $line, $matches)) {
                foreach ($matches[0] as $url) {
                    $service = $this->serviceName(rtrim($url, '.,);'));
                    $serviceKey = $graph->addSymbol(['path' => $path, 'language' => $language, 'type' => 'external_service', 'name' => $service, 'qualified_name' => 'service:' . $service, 'start_line' => $lineNumber, 'confidence' => 'medium']);
                    $graph->addRelationship(['source_key' => $activeSymbol, 'target_key' => $serviceKey, 'type' => 'calls_service', 'confidence' => 'medium', 'evidence_path' => $path, 'line' => $lineNumber, 'excerpt' => $this->excerpt($line)]);
                }
            }
            if (preg_match('/\b(?:app|router)\.(get|post|put|patch|delete|options|head|all)\(\s*[\'\"]([^\'\"]+)[\'\"]\s*,\s*([A-Za-z_$][A-Za-z0-9_$.]*)?/i', $line, $route)) {
                $handler = isset($route[3]) ? $graph->findSymbolKey($path, basename(str_replace('.', '/', $route[3]))) : null;
                $graph->addRoute(['path' => $path, 'handler_key' => $handler, 'framework' => 'Express-style', 'method' => $route[1], 'route_path' => $route[2], 'line' => $lineNumber, 'confidence' => 'high']);
            }
        }
    }
}

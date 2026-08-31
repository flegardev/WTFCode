<?php

declare(strict_types=1);

final class PythonLanguageAdapter extends AbstractLanguageAdapter
{
    public function supports(array $file): bool
    {
        return ($file['language'] ?? '') === 'Python';
    }

    public function extract(array $file, SymbolGraph $graph): void
    {
        $path = (string) $file['path'];
        $moduleKey = $this->moduleKey($file, $graph);
        $scopes = [];
        $decorators = [];
        foreach ($this->lines($file) as $offset => $line) {
            $lineNumber = $offset + 1;
            $trimmed = trim($line);
            $indent = strlen($line) - strlen(ltrim($line, " \t"));
            while ($scopes !== [] && $trimmed !== '' && $indent <= $scopes[array_key_last($scopes)]['indent']) array_pop($scopes);
            $parent = $scopes === [] ? null : $scopes[array_key_last($scopes)];
            $sourceKey = $parent['key'] ?? $moduleKey;
            if (str_starts_with($trimmed, '@')) {
                $decorators[] = $trimmed;
                continue;
            }
            if (preg_match('/^class\s+([A-Za-z_][A-Za-z0-9_]*)(?:\(([^)]*)\))?\s*:/', $trimmed, $match)) {
                $key = $graph->addSymbol(['path' => $path, 'language' => 'Python', 'type' => 'class', 'name' => $match[1], 'qualified_name' => $path . '#' . $match[1], 'start_line' => $lineNumber, 'signature' => $this->excerpt($trimmed), 'visibility' => str_starts_with($match[1], '_') ? 'private' : 'public', 'exported' => !str_starts_with($match[1], '_'), 'confidence' => 'high', 'metadata' => ['decorators' => $decorators]]);
                foreach (preg_split('/\s*,\s*/', $match[2] ?? '') ?: [] as $base) if ($base !== '') $graph->addRelationship(['source_key' => $key, 'target_name' => $base, 'external_name' => $base, 'type' => 'extends', 'confidence' => 'high', 'evidence_path' => $path, 'line' => $lineNumber, 'excerpt' => $this->excerpt($line)]);
                $scopes[] = ['key' => $key, 'name' => $match[1], 'indent' => $indent, 'type' => 'class'];
                $decorators = [];
                continue;
            }
            if (preg_match('/^(?:async\s+)?def\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(([^)]*)\)/', $trimmed, $match)) {
                $isMethod = ($parent['type'] ?? '') === 'class';
                $qualified = $isMethod ? $parent['name'] . '.' . $match[1] : $path . '#' . $match[1];
                $key = $graph->addSymbol(['path' => $path, 'language' => 'Python', 'type' => $isMethod ? 'method' : 'function', 'name' => $match[1], 'qualified_name' => $qualified, 'parent_key' => $isMethod ? $parent['key'] : null, 'start_line' => $lineNumber, 'signature' => $this->excerpt($trimmed), 'visibility' => str_starts_with($match[1], '_') ? 'private' : 'public', 'exported' => !$isMethod && !str_starts_with($match[1], '_'), 'confidence' => 'high', 'metadata' => ['decorators' => $decorators, 'parameters' => trim($match[2])]]);
                $scopes[] = ['key' => $key, 'name' => $match[1], 'indent' => $indent, 'type' => 'function'];
                $decorators = [];
                continue;
            }
            $decorators = [];
            if (preg_match('/^(?:from\s+([A-Za-z0-9_.]+)\s+import|import\s+([A-Za-z0-9_.]+))/', $trimmed, $match)) {
                $target = $match[1] ?: ($match[2] ?? '');
                $graph->addRelationship(['source_key' => $moduleKey, 'target_name' => $target, 'external_name' => $target, 'type' => 'imports', 'confidence' => 'high', 'evidence_path' => $path, 'line' => $lineNumber, 'excerpt' => $this->excerpt($line)]);
            }
            if (preg_match_all('/\b([A-Za-z_][A-Za-z0-9_.]*)\s*\(/', $line, $matches)) {
                foreach ($matches[1] as $target) {
                    if (in_array($target, ['if', 'for', 'while', 'def', 'class'], true)) continue;
                    $short = basename(str_replace('.', '/', $target));
                    $graph->addRelationship(['source_key' => $sourceKey, 'target_name' => $short, 'external_name' => $target, 'type' => 'calls', 'confidence' => 'low', 'evidence_path' => $path, 'line' => $lineNumber, 'excerpt' => $this->excerpt($line)]);
                }
            }
            if (preg_match_all('/(?:os\.getenv|environ\.get)\(\s*[\'\"]([A-Z][A-Z0-9_]+)[\'\"]|os\.environ\s*\[\s*[\'\"]([A-Z][A-Z0-9_]+)[\'\"]\s*\]/', $line, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $name = $match[1] !== '' ? $match[1] : ($match[2] ?? '');
                    if ($name === '') continue;
                    $envKey = $graph->addSymbol(['path' => $path, 'language' => 'Python', 'type' => 'environment_variable', 'name' => $name, 'qualified_name' => 'env:' . $name, 'start_line' => $lineNumber, 'confidence' => 'high']);
                    $graph->addRelationship(['source_key' => $sourceKey, 'target_key' => $envKey, 'type' => 'reads_env', 'confidence' => 'high', 'evidence_path' => $path, 'line' => $lineNumber, 'excerpt' => $this->excerpt($line)]);
                }
            }
            if (preg_match_all('#https?://[^\s\'\"<>]+#i', $line, $matches)) {
                foreach ($matches[0] as $url) {
                    $service = $this->serviceName(rtrim($url, '.,);'));
                    $serviceKey = $graph->addSymbol(['path' => $path, 'language' => 'Python', 'type' => 'external_service', 'name' => $service, 'qualified_name' => 'service:' . $service, 'start_line' => $lineNumber, 'confidence' => 'medium']);
                    $graph->addRelationship(['source_key' => $sourceKey, 'target_key' => $serviceKey, 'type' => 'calls_service', 'confidence' => 'medium', 'evidence_path' => $path, 'line' => $lineNumber, 'excerpt' => $this->excerpt($line)]);
                }
            }
        }
    }
}

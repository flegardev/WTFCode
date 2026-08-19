<?php

declare(strict_types=1);

final class PhpLanguageAdapter extends AbstractLanguageAdapter
{
    public function supports(array $file): bool
    {
        return ($file['language'] ?? '') === 'PHP';
    }

    public function extract(array $file, SymbolGraph $graph): void
    {
        $path = (string) $file['path'];
        $moduleKey = $this->moduleKey($file, $graph);
        $namespace = '';
        $depth = 0;
        $classes = [];
        $callables = [];
        $blockedCalls = ['if', 'elseif', 'while', 'for', 'foreach', 'switch', 'catch', 'isset', 'empty', 'array', 'echo', 'print', 'include', 'include_once', 'require', 'require_once', 'unset', 'match', 'return', 'function'];

        foreach ($this->lines($file) as $offset => $line) {
            $lineNumber = $offset + 1;
            $trimmed = trim($line);
            if (preg_match('/^namespace\s+([^;{]+)/i', $trimmed, $match)) $namespace = trim($match[1]);

            $classKey = $classes === [] ? null : $classes[array_key_last($classes)]['key'];
            $className = $classes === [] ? null : $classes[array_key_last($classes)]['name'];
            $sourceKey = $callables === [] ? ($classKey ?? $moduleKey) : $callables[array_key_last($callables)]['key'];

            if (preg_match('/^(?:(?:final|abstract|readonly)\s+)*(class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)(.*)$/i', $trimmed, $match)) {
                $type = strtolower($match[1]);
                $className = $match[2];
                $qualified = ltrim($namespace . '\\' . $className, '\\');
                $classKey = $graph->addSymbol([
                    'path' => $path, 'language' => 'PHP', 'type' => $type, 'name' => $className,
                    'qualified_name' => $qualified, 'start_line' => $lineNumber, 'signature' => $this->excerpt($trimmed),
                    'visibility' => 'public', 'exported' => true, 'confidence' => 'high',
                ]);
                $classes[] = ['key' => $classKey, 'name' => $className, 'depth' => $depth + 1, 'pending' => !str_contains($trimmed, '{')];
                $sourceKey = $classKey;
                if (preg_match('/\bextends\s+([A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)/i', $match[3], $parent)) {
                    $graph->addRelationship(['source_key' => $classKey, 'target_name' => trim($parent[1], '\\'), 'external_name' => trim($parent[1], '\\'), 'type' => 'extends', 'confidence' => 'high', 'evidence_path' => $path, 'line' => $lineNumber, 'excerpt' => $this->excerpt($line)]);
                }
                if (preg_match('/\bimplements\s+([^\{]+)/i', $match[3], $interfaces)) {
                    foreach (preg_split('/\s*,\s*/', trim($interfaces[1])) ?: [] as $interface) {
                        $graph->addRelationship(['source_key' => $classKey, 'target_name' => trim($interface, '\\ '), 'external_name' => trim($interface, '\\ '), 'type' => 'implements', 'confidence' => 'high', 'evidence_path' => $path, 'line' => $lineNumber, 'excerpt' => $this->excerpt($line)]);
                    }
                }
            }

            if (preg_match('/^(?:(public|protected|private)\s+)?(?:(?:static|final|abstract)\s+)*function\s+&?\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(([^)]*)\)/i', $trimmed, $match)) {
                $name = $match[2];
                $type = $classKey === null ? 'function' : 'method';
                $qualified = $className === null ? ltrim($namespace . '\\' . $name, '\\') : ltrim($namespace . '\\' . $className . '::' . $name, '\\');
                $callableKey = $graph->addSymbol([
                    'path' => $path, 'language' => 'PHP', 'type' => $type, 'name' => $name, 'qualified_name' => $qualified,
                    'parent_key' => $classKey, 'start_line' => $lineNumber, 'signature' => $this->excerpt($trimmed),
                    'visibility' => $match[1] !== '' ? strtolower($match[1]) : 'public', 'exported' => $classKey === null,
                    'confidence' => 'high', 'metadata' => ['parameters' => trim($match[3])],
                ]);
                if (!str_ends_with($trimmed, ';')) $callables[] = ['key' => $callableKey, 'depth' => $depth + 1, 'pending' => !str_contains($trimmed, '{')];
                $sourceKey = $callableKey;
            }

            if ($classKey !== null && preg_match('/^(public|protected|private)\s+(?:static\s+)?(?:readonly\s+)?(?:(?:[?A-Za-z_][A-Za-z0-9_?|&]*(?:\\\\[A-Za-z_][A-Za-z0-9_?|&]*)*)\s+)?\$([A-Za-z_][A-Za-z0-9_]*)/i', $trimmed, $match)) {
                $graph->addSymbol(['path' => $path, 'language' => 'PHP', 'type' => 'property', 'name' => '$' . $match[2], 'qualified_name' => $className . '::$' . $match[2], 'parent_key' => $classKey, 'start_line' => $lineNumber, 'signature' => $this->excerpt($trimmed), 'visibility' => strtolower($match[1]), 'confidence' => 'high']);
            }
            if ($classKey !== null && preg_match('/^(?:(public|protected|private)\s+)?const\s+([A-Za-z_][A-Za-z0-9_]*)/i', $trimmed, $match)) {
                $graph->addSymbol(['path' => $path, 'language' => 'PHP', 'type' => 'constant', 'name' => $match[2], 'qualified_name' => $className . '::' . $match[2], 'parent_key' => $classKey, 'start_line' => $lineNumber, 'visibility' => $match[1] ?: 'public', 'confidence' => 'high']);
            }

            if (preg_match('/^use\s+([^;{]+);/i', $trimmed, $match)) {
                foreach (preg_split('/\s*,\s*/', $match[1]) ?: [] as $used) {
                    $used = preg_replace('/\s+as\s+.+$/i', '', trim($used)) ?? trim($used);
                    $graph->addRelationship(['source_key' => $classKey ?? $moduleKey, 'target_name' => trim($used, '\\'), 'external_name' => trim($used, '\\'), 'type' => $classKey === null ? 'imports' : 'uses_trait', 'confidence' => 'high', 'evidence_path' => $path, 'line' => $lineNumber, 'excerpt' => $this->excerpt($line)]);
                }
            }

            if (preg_match_all('/\bnew\s+([A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)/', $line, $matches)) {
                foreach ($matches[1] as $target) $graph->addRelationship(['source_key' => $sourceKey, 'target_name' => trim($target, '\\'), 'external_name' => trim($target, '\\'), 'type' => 'instantiates', 'confidence' => 'high', 'evidence_path' => $path, 'line' => $lineNumber, 'excerpt' => $this->excerpt($line)]);
            }
            if (!str_contains(strtolower($line), 'function ')) {
                if (preg_match_all('/(?:->|::)([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $line, $matches)) {
                    foreach ($matches[1] as $target) $graph->addRelationship(['source_key' => $sourceKey, 'target_name' => $target, 'external_name' => $target, 'type' => 'calls', 'confidence' => 'medium', 'evidence_path' => $path, 'line' => $lineNumber, 'excerpt' => $this->excerpt($line)]);
                }
                if (preg_match_all('/(?<![>:\w])([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $line, $matches)) {
                    foreach ($matches[1] as $target) {
                        if (in_array(strtolower($target), $blockedCalls, true)) continue;
                        $graph->addRelationship(['source_key' => $sourceKey, 'target_name' => $target, 'external_name' => $target, 'type' => 'calls', 'confidence' => 'low', 'evidence_path' => $path, 'line' => $lineNumber, 'excerpt' => $this->excerpt($line)]);
                    }
                }
            }

            if (preg_match_all('/(?:getenv|env)\(\s*[\'\"]([A-Z][A-Z0-9_]+)[\'\"]|\$_ENV\s*\[\s*[\'\"]([A-Z][A-Z0-9_]+)[\'\"]\s*\]/', $line, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $name = $match[1] !== '' ? $match[1] : ($match[2] ?? '');
                    if ($name === '') continue;
                    $envKey = $graph->addSymbol(['path' => $path, 'language' => 'PHP', 'type' => 'environment_variable', 'name' => $name, 'qualified_name' => 'env:' . $name, 'start_line' => $lineNumber, 'confidence' => 'high']);
                    $graph->addRelationship(['source_key' => $sourceKey, 'target_key' => $envKey, 'type' => 'reads_env', 'confidence' => 'high', 'evidence_path' => $path, 'line' => $lineNumber, 'excerpt' => $this->excerpt($line)]);
                }
            }
            $tableNames = [];
            foreach ([
                '/\b(?:SELECT|DELETE)\b.{0,350}?\b(?:FROM|JOIN)\s+[`\"]?([A-Za-z_][A-Za-z0-9_.]*)/i',
                '/\bINSERT\s+(?:IGNORE\s+)?INTO\s+[`\"]?([A-Za-z_][A-Za-z0-9_.]*)/i',
                '/\bUPDATE\s+[`\"]?([A-Za-z_][A-Za-z0-9_.]*)\s+(?:SET|AS)\b/i',
                '/\b(?:CREATE|ALTER|DROP|TRUNCATE)\s+TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?[`\"]?([A-Za-z_][A-Za-z0-9_.]*)/i',
            ] as $tablePattern) {
                if (preg_match_all($tablePattern, $line, $matches)) $tableNames = array_merge($tableNames, $matches[1]);
            }
            foreach (array_unique($tableNames) as $table) {
                $tableKey = $graph->addSymbol(['path' => $path, 'language' => 'PHP', 'type' => 'table', 'name' => $table, 'qualified_name' => 'table:' . strtolower($table), 'start_line' => $lineNumber, 'confidence' => 'medium', 'metadata' => ['reference_only' => true]]);
                $graph->addRelationship(['source_key' => $sourceKey, 'target_key' => $tableKey, 'type' => 'uses_table', 'confidence' => 'medium', 'evidence_path' => $path, 'line' => $lineNumber, 'excerpt' => $this->excerpt($line)]);
            }
            if (preg_match_all('/(?:->|::)table\(\s*[\'\"]([A-Za-z_][A-Za-z0-9_.]*)[\'\"]/i', $line, $matches)) {
                foreach ($matches[1] as $table) {
                    $tableKey = $graph->addSymbol(['path' => $path, 'language' => 'PHP', 'type' => 'table', 'name' => $table, 'qualified_name' => 'table:' . strtolower($table), 'start_line' => $lineNumber, 'confidence' => 'high', 'metadata' => ['reference_only' => true, 'query_builder' => true]]);
                    $graph->addRelationship(['source_key' => $sourceKey, 'target_key' => $tableKey, 'type' => 'uses_table', 'confidence' => 'high', 'evidence_path' => $path, 'line' => $lineNumber, 'excerpt' => $this->excerpt($line)]);
                }
            }
            $isExecutableUrl = !str_ends_with(strtolower($path), '.blade.php')
                && (str_contains($line, '=>') || preg_match('/(?:\$[A-Za-z_][A-Za-z0-9_]*\s*=|(?:->|::)(?:get|post|put|patch|delete|request|send)\s*\()/i', $line) === 1);
            if ($isExecutableUrl && preg_match_all('#https?://[^\s\'\"<>]+#i', $line, $matches)) {
                foreach ($matches[0] as $url) {
                    $service = $this->serviceName(rtrim($url, '.,);'));
                    $serviceKey = $graph->addSymbol(['path' => $path, 'language' => 'PHP', 'type' => 'external_service', 'name' => $service, 'qualified_name' => 'service:' . $service, 'start_line' => $lineNumber, 'confidence' => 'medium']);
                    $graph->addRelationship(['source_key' => $sourceKey, 'target_key' => $serviceKey, 'type' => 'calls_service', 'confidence' => 'medium', 'evidence_path' => $path, 'line' => $lineNumber, 'excerpt' => $this->excerpt($line)]);
                }
            }

            $structural = preg_replace('/([\'\"]).*?(?<!\\\\)\1/', '', preg_replace('#//.*$#', '', $line) ?? $line) ?? $line;
            $openingBraces = substr_count($structural, '{');
            $depth += $openingBraces - substr_count($structural, '}');
            if ($openingBraces > 0 && $callables !== [] && $callables[array_key_last($callables)]['pending']) {
                $callables[array_key_last($callables)]['depth'] = max(1, $depth);
                $callables[array_key_last($callables)]['pending'] = false;
            }
            if ($openingBraces > 0 && $classes !== [] && $classes[array_key_last($classes)]['pending']) {
                $classes[array_key_last($classes)]['depth'] = max(1, $depth);
                $classes[array_key_last($classes)]['pending'] = false;
            }
            while ($callables !== [] && !$callables[array_key_last($callables)]['pending'] && $depth < $callables[array_key_last($callables)]['depth']) array_pop($callables);
            while ($classes !== [] && !$classes[array_key_last($classes)]['pending'] && $depth < $classes[array_key_last($classes)]['depth']) array_pop($classes);
        }
    }
}

<?php

declare(strict_types=1);

final class SqlLanguageAdapter extends AbstractLanguageAdapter
{
    public function supports(array $file): bool
    {
        return ($file['language'] ?? '') === 'SQL';
    }

    public function extract(array $file, SymbolGraph $graph): void
    {
        $path = (string) $file['path'];
        $moduleKey = $this->moduleKey($file, $graph);
        $table = null;
        foreach ($this->lines($file) as $offset => $line) {
            $lineNumber = $offset + 1;
            $trimmed = trim($line);
            if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`\"]?([A-Za-z_][A-Za-z0-9_.]*)/i', $trimmed, $match)) {
                $tableKey = $graph->addSymbol(['path' => $path, 'language' => 'SQL', 'type' => 'table', 'name' => $match[1], 'qualified_name' => 'table:' . strtolower($match[1]), 'start_line' => $lineNumber, 'signature' => $this->excerpt($trimmed), 'exported' => true, 'confidence' => 'high']);
                $table = ['key' => $tableKey, 'name' => $match[1]];
                $graph->addRelationship(['source_key' => $moduleKey, 'target_key' => $tableKey, 'type' => 'defines', 'confidence' => 'high', 'evidence_path' => $path, 'line' => $lineNumber, 'excerpt' => $this->excerpt($line)]);
                continue;
            }
            if (preg_match('/^CREATE\s+(?:OR\s+REPLACE\s+)?(VIEW|FUNCTION|PROCEDURE|TRIGGER)\s+[`\"]?([A-Za-z_][A-Za-z0-9_.]*)/i', $trimmed, $match)) {
                $key = $graph->addSymbol(['path' => $path, 'language' => 'SQL', 'type' => strtolower($match[1]), 'name' => $match[2], 'qualified_name' => strtolower($match[1]) . ':' . strtolower($match[2]), 'start_line' => $lineNumber, 'signature' => $this->excerpt($trimmed), 'exported' => true, 'confidence' => 'high']);
                $graph->addRelationship(['source_key' => $moduleKey, 'target_key' => $key, 'type' => 'defines', 'confidence' => 'high', 'evidence_path' => $path, 'line' => $lineNumber, 'excerpt' => $this->excerpt($line)]);
            }
            if ($table !== null && preg_match('/^[`\"]?([A-Za-z_][A-Za-z0-9_]*)[`\"]?\s+([A-Za-z]+(?:\s*\([^)]*\))?)/', $trimmed, $column) && !in_array(strtoupper($column[1]), ['PRIMARY', 'UNIQUE', 'KEY', 'CONSTRAINT', 'FOREIGN', 'CHECK'], true)) {
                $graph->addSymbol(['path' => $path, 'language' => 'SQL', 'type' => 'column', 'name' => $column[1], 'qualified_name' => $table['name'] . '.' . $column[1], 'parent_key' => $table['key'], 'start_line' => $lineNumber, 'signature' => $this->excerpt($trimmed), 'confidence' => 'high', 'metadata' => ['data_type' => strtoupper($column[2])]]);
            }
            if (preg_match('/FOREIGN\s+KEY\s*\([^)]*\)\s*REFERENCES\s+[`\"]?([A-Za-z_][A-Za-z0-9_.]*)/i', $trimmed, $foreign) && $table !== null) {
                $graph->addRelationship(['source_key' => $table['key'], 'target_name' => $foreign[1], 'external_name' => 'table:' . strtolower($foreign[1]), 'type' => 'foreign_key_to', 'confidence' => 'high', 'evidence_path' => $path, 'line' => $lineNumber, 'excerpt' => $this->excerpt($line)]);
            }
            if ($table !== null && preg_match('/^\)\s*(?:ENGINE|;|$)/i', $trimmed)) $table = null;
        }
    }
}

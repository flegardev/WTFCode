<?php

declare(strict_types=1);

final class TreeSitterAnalyzerProvider extends NodeWorkerAnalyzerProvider
{
    public function id(): string { return 'tree-sitter'; }
    public function version(): string { return 'web-tree-sitter-0.20.8+grammars-0.1.13'; }
    public function supportedLanguages(): array { return ['JavaScript', 'TypeScript', 'Python', 'PHP', 'Go', 'Rust', 'Java', 'C', 'C++', 'C#', 'Ruby', 'HTML', 'CSS', 'JSON', 'YAML']; }
    public function capabilities(): array { return ['syntax', 'symbols', 'calls', 'dependencies']; }
    protected function workerFile(): string { return 'workers/tree-sitter.mjs'; }
    protected function dependencyMarker(): string { return 'node_modules/web-tree-sitter/package.json'; }
    protected function maxFiles(): int { return 600; }
    protected function maxInputBytes(): int { return 6_291_456; }
}

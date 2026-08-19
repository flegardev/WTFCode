<?php

declare(strict_types=1);

final class AstGrepAnalyzerProvider extends NodeWorkerAnalyzerProvider
{
    public function id(): string { return 'ast-grep'; }
    public function version(): string { return 'napi-0.45.1'; }
    public function supportedLanguages(): array { return ['TypeScript', 'JavaScript']; }
    public function capabilities(): array { return ['syntax', 'http', 'auth', 'database', 'process', 'filesystem', 'environment', 'ui']; }
    protected function workerFile(): string { return 'workers/ast-grep.mjs'; }
    protected function dependencyMarker(): string { return 'node_modules/@ast-grep/napi/package.json'; }
    protected function maxFiles(): int { return 1000; }
    protected function maxInputBytes(): int { return 8_388_608; }
}

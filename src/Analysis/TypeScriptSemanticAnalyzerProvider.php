<?php

declare(strict_types=1);

final class TypeScriptSemanticAnalyzerProvider extends NodeWorkerAnalyzerProvider
{
    public function id(): string { return 'typescript-semantic'; }
    public function version(): string { return 'ts-morph-28.0.0+typescript-6.0.2'; }
    public function supportedLanguages(): array { return ['TypeScript', 'JavaScript']; }
    public function capabilities(): array { return ['syntax', 'symbols', 'definitions', 'references', 'calls', 'types', 'inheritance', 'dependencies']; }
    protected function workerFile(): string { return 'workers/typescript-semantic.mjs'; }
    protected function dependencyMarker(): string { return 'node_modules/ts-morph/package.json'; }
}

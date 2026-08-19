<?php

declare(strict_types=1);

use PhpParser\Error as PhpParserError;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

final class PhpAstAnalyzerProvider implements AnalyzerProviderInterface
{
    public function id(): string { return 'php-parser'; }
    public function version(): string { return class_exists(ParserFactory::class) ? '5.8.0' : 'unavailable'; }
    public function supportedLanguages(): array { return ['PHP']; }
    public function capabilities(): array { return ['syntax', 'symbols', 'definitions', 'calls', 'routes', 'types', 'inheritance', 'dependencies']; }
    public function isAvailable(): bool { return class_exists(ParserFactory::class); }

    public function healthCheck(): AnalyzerHealth
    {
        return $this->isAvailable()
            ? new AnalyzerHealth('ready', 'nikic/PHP-Parser is available without executing repository PHP.', $this->version())
            : new AnalyzerHealth('unavailable', 'Run Composer install in WTFCode to enable PHP AST analysis.');
    }

    public function analyze(AnalysisRequest $request): AnalyzerResult
    {
        if (!$this->isAvailable()) return AnalyzerResult::unavailable($this->id(), $this->version(), 'PHP-Parser is not installed.');
        $started = hrtime(true);
        $graph = new SymbolGraph();
        $findings = [];
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        foreach ($request->files() as $file) {
            if (($file['language'] ?? '') !== 'PHP') continue;
            try {
                $statements = $parser->parse((string) ($file['content'] ?? ''));
                if ($statements === null) continue;
                $moduleKey = $graph->addModule($file);
                $traverser = new NodeTraverser();
                $traverser->addVisitor(new NameResolver(null, ['preserveOriginalNames' => true]));
                $traverser->addVisitor(new PhpAstEvidenceVisitor($file, $graph, $moduleKey));
                $traverser->traverse($statements);
            } catch (PhpParserError $error) {
                $findings[] = [
                    'severity' => 'attention', 'type' => 'php_parse_error', 'title' => 'PHP file could not be parsed as valid syntax',
                    'explanation' => 'PHP-Parser skipped this file; other analyzers can still contribute evidence.',
                    'path' => (string) ($file['path'] ?? ''), 'evidence' => ['line' => max(1, $error->getStartLine())],
                ];
            }
        }
        $graph->finalize();
        $status = $findings === [] ? AnalyzerResult::SUCCESS : AnalyzerResult::PARTIAL;
        return new AnalyzerResult(
            $this->id(), $this->version(), $status, $graph->toArray(), $findings,
            (int) round((hrtime(true) - $started) / 1_000_000),
            $findings === [] ? null : count($findings) . ' PHP file(s) had parse errors.',
        );
    }
}

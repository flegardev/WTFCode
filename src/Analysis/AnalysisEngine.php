<?php

declare(strict_types=1);

final class AnalysisEngine
{
    public const VERSION = 'v3.6-intel';
    public const NATIVE_VERSION = '2.0.0';

    /** @var array<int, LanguageAdapterInterface> */
    private array $languageAdapters;
    /** @var array<int, FrameworkAdapterInterface> */
    private array $frameworkAdapters;

    public function __construct(?array $languageAdapters = null, ?array $frameworkAdapters = null)
    {
        $this->languageAdapters = $languageAdapters ?? [
            new PhpLanguageAdapter(),
            new JavaScriptLanguageAdapter(),
            new PythonLanguageAdapter(),
            new SqlLanguageAdapter(),
        ];
        $this->frameworkAdapters = $frameworkAdapters ?? [
            new PlainPhpAdapter(),
            new LaravelAdapter(),
            new ReactAdapter(),
            new NextJsAdapter(),
            new VueAdapter(),
            new FastApiAdapter(),
            new DjangoAdapter(),
        ];
    }

    /** @param array<int, array<string, mixed>> $files */
    public function analyse(array $files): array
    {
        $graph = new SymbolGraph();
        foreach ($files as $file) {
            foreach ($this->languageAdapters as $adapter) {
                if (!$adapter->supports($file)) continue;
                $adapter->extract($file, $graph);
                break;
            }
        }
        $frameworkEvidenceFiles = array_values(array_filter($files, static fn (array $file): bool => preg_match('#^(?:tests?|specs?|fixtures?)/#i', (string) $file['path']) !== 1));
        foreach ($this->frameworkAdapters as $adapter) $adapter->enrich($frameworkEvidenceFiles, $graph);
        $graph->finalize();
        return $graph->toArray();
    }
}

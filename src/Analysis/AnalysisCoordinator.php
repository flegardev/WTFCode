<?php

declare(strict_types=1);

final class AnalysisCoordinator
{
    public function __construct(
        private readonly AnalyzerRegistry $registry = new AnalyzerRegistry(),
        private readonly EvidenceFusion $fusion = new EvidenceFusion(),
    ) {
    }

    /** @return array<string, mixed> */
    public function analyze(AnalysisRequest $request): array
    {
        $results = [];
        foreach ($this->registry->all() as $index => $provider) {
            $id = 'provider-' . $index;
            $version = 'unknown';
            try {
                $id = $provider->id();
                $version = $provider->version();
                if (!$provider->isAvailable()) {
                    $results[] = AnalyzerResult::unavailable($id, $version, 'Analyzer is not installed or not supported on this machine.');
                    continue;
                }
                $results[] = $provider->analyze($request);
            } catch (Throwable $exception) {
                $results[] = AnalyzerResult::failed($id, $version, 'Analyzer failed independently: ' . get_class($exception));
            }
        }
        return $this->fusion->fuse($results);
    }
}

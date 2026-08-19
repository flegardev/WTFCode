<?php

declare(strict_types=1);

interface AnalyzerProviderInterface
{
    public function id(): string;

    public function version(): string;

    /** @return array<int, string> */
    public function supportedLanguages(): array;

    /** @return array<int, string> */
    public function capabilities(): array;

    public function isAvailable(): bool;

    public function analyze(AnalysisRequest $request): AnalyzerResult;

    public function healthCheck(): AnalyzerHealth;
}

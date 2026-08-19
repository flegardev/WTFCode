<?php

declare(strict_types=1);

final class AnalysisRequest
{
    private string $repositoryRoot;
    /** @var array<int, array<string, mixed>> */
    private array $files;
    private string $profile;

    /** @param array<int, array<string, mixed>> $files */
    public function __construct(string $repositoryRoot, array $files, string $profile = AnalysisProfile::QUICK)
    {
        $resolved = realpath($repositoryRoot);
        if ($resolved === false || !is_dir($resolved)) {
            throw new InvalidArgumentException('Analysis requires an existing repository directory.');
        }
        $this->repositoryRoot = rtrim($resolved, DIRECTORY_SEPARATOR);
        $this->files = array_values($files);
        $this->profile = AnalysisProfile::normalize($profile);
    }

    public function repositoryRoot(): string
    {
        return $this->repositoryRoot;
    }

    /** @return array<int, array<string, mixed>> */
    public function files(): array
    {
        return $this->files;
    }

    public function profile(): string
    {
        return $this->profile;
    }

    /** @return array<int, string> */
    public function languages(): array
    {
        $languages = array_filter(array_column($this->files, 'language'), 'is_string');
        sort($languages, SORT_STRING | SORT_FLAG_CASE);
        return array_values(array_unique($languages));
    }
}

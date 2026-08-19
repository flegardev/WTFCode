<?php

declare(strict_types=1);

final class AnalysisRequest
{
    private string $repositoryRoot;
    /** @var array<int, array<string, mixed>> */
    private array $files;
    private string $profile;
    /** @var array<int, string> */
    private array $changedPaths;

    /** @param array<int, array<string, mixed>> $files */
    public function __construct(
        string $repositoryRoot,
        array $files,
        string $profile = AnalysisProfile::QUICK,
        private readonly ?string $currentRevision = null,
        private readonly ?string $previousRevision = null,
        array $changedPaths = [],
    )
    {
        $resolved = realpath($repositoryRoot);
        if ($resolved === false || !is_dir($resolved)) {
            throw new InvalidArgumentException('Analysis requires an existing repository directory.');
        }
        $this->repositoryRoot = rtrim($resolved, DIRECTORY_SEPARATOR);
        $this->files = array_values($files);
        $this->profile = AnalysisProfile::normalize($profile);
        $this->changedPaths = array_values(array_unique(array_filter($changedPaths, 'is_string')));
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

    public function revision(): string
    {
        if ($this->currentRevision !== null && preg_match('/^[a-f0-9]{40,64}$/i', $this->currentRevision)) return strtolower($this->currentRevision);
        $identities = array_map(static fn (array $file): string => (string) ($file['path'] ?? '') . ':' . (string) ($file['hash'] ?? hash('sha256', (string) ($file['content'] ?? ''))), $this->files);
        sort($identities, SORT_STRING);
        return hash('sha256', implode('|', $identities));
    }

    public function previousRevision(): ?string { return $this->previousRevision; }
    /** @return array<int, string> */
    public function changedPaths(): array { return $this->changedPaths; }

    public function subset(array $paths): self
    {
        $lookup = array_fill_keys(array_filter($paths, 'is_string'), true);
        $files = array_values(array_filter($this->files, static fn (array $file): bool => isset($lookup[$file['path'] ?? ''])));
        return new self($this->repositoryRoot, $files, $this->profile, $this->currentRevision, $this->previousRevision, $this->changedPaths);
    }

    /** @return array<int, string> */
    public function languages(): array
    {
        $languages = array_filter(array_column($this->files, 'language'), 'is_string');
        sort($languages, SORT_STRING | SORT_FLAG_CASE);
        return array_values(array_unique($languages));
    }
}

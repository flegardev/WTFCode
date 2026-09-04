<?php

declare(strict_types=1);

namespace WTFCode\Scanning;

final readonly class RepositoryLimits
{
    /** @param list<string> $ignoredDirectories */
    public function __construct(
        public int $maxFiles = 3_000,
        public int $maxFileBytes = 262_144,
        public int $maxTotalBytes = 20_971_520,
        public array $ignoredDirectories = [
            '.git',
            '.idea',
            '.vscode',
            '.playwright-cli',
            'node_modules',
            'vendor',
            '.next',
            'dist',
            'build',
            'coverage',
            '.turbo',
            '.cache',
            'storage',
            'tmp',
            'temp',
        ],
    ) {
        if ($this->maxFiles < 1 || $this->maxFileBytes < 1 || $this->maxTotalBytes < 1) {
            throw new \InvalidArgumentException('Repository scan limits must be positive integers.');
        }
    }
}

<?php

declare(strict_types=1);

final class ProcessRunResult
{
    public function __construct(
        public readonly array $command,
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly int $durationMs,
        public readonly bool $timedOut,
        public readonly bool $stdoutTruncated,
        public readonly bool $stderrTruncated,
    ) {
    }

    public function succeeded(): bool
    {
        return !$this->timedOut && $this->exitCode === 0;
    }
}

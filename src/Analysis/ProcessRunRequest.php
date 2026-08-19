<?php

declare(strict_types=1);

final class ProcessRunRequest
{
    private const ALLOWED_ENVIRONMENT = [
        'PATH', 'PATHEXT', 'SystemRoot', 'WINDIR', 'COMSPEC', 'TEMP', 'TMP',
    ];

    /** @var array<int, string> */
    public readonly array $command;
    public readonly string $workingDirectory;
    /** @var array<string, string> */
    public readonly array $environment;

    /**
     * @param array<int, string> $command
     * @param array<string, string> $environment
     */
    public function __construct(
        array $command,
        string $workingDirectory,
        public readonly int $timeoutSeconds = 30,
        public readonly int $stdoutLimitBytes = 2_097_152,
        public readonly int $stderrLimitBytes = 524_288,
        array $environment = [],
    ) {
        if ($command === [] || count(array_filter($command, 'is_string')) !== count($command)) {
            throw new InvalidArgumentException('Processes require a non-empty string argument array.');
        }
        foreach ($command as $argument) {
            if ($argument === '' || str_contains($argument, "\0")) {
                throw new InvalidArgumentException('Process arguments cannot be empty or contain null bytes.');
            }
        }
        $resolved = realpath($workingDirectory);
        if ($resolved === false || !is_dir($resolved)) {
            throw new InvalidArgumentException('Process working directory must be an existing directory.');
        }
        if ($timeoutSeconds < 1 || $timeoutSeconds > 300) {
            throw new InvalidArgumentException('Process timeout must be between 1 and 300 seconds.');
        }
        if ($stdoutLimitBytes < 1024 || $stderrLimitBytes < 1024) {
            throw new InvalidArgumentException('Process output limits must be at least 1 KB.');
        }
        foreach ($environment as $key => $value) {
            if (!in_array($key, self::ALLOWED_ENVIRONMENT, true) || !is_string($value) || str_contains($value, "\0")) {
                throw new InvalidArgumentException('Process environment contains a non-allowlisted entry.');
            }
        }
        $allowed = [];
        foreach (self::ALLOWED_ENVIRONMENT as $key) {
            $value = getenv($key);
            if (is_string($value) && $value !== '') $allowed[$key] = $value;
        }
        $this->command = array_values($command);
        $this->workingDirectory = $resolved;
        $this->environment = array_merge($allowed, $environment);
    }
}

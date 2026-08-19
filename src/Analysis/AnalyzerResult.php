<?php

declare(strict_types=1);

final class AnalyzerResult
{
    public const SUCCESS = 'success';
    public const PARTIAL = 'partial';
    public const UNAVAILABLE = 'unavailable';
    public const FAILED = 'failed';

    /** @param array<string, mixed> $graph @param array<int, array<string, mixed>> $findings */
    public function __construct(
        public readonly string $engine,
        public readonly string $engineVersion,
        public readonly string $status,
        public readonly array $graph = [],
        public readonly array $findings = [],
        public readonly int $durationMs = 0,
        public readonly ?string $message = null,
    ) {
    }

    public static function unavailable(string $engine, string $version, string $message): self
    {
        return new self($engine, $version, self::UNAVAILABLE, message: $message);
    }

    public static function failed(string $engine, string $version, string $message, int $durationMs = 0): self
    {
        return new self($engine, $version, self::FAILED, durationMs: $durationMs, message: $message);
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $message = $this->message === null ? null : preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $this->message);
        return [
            'engine' => $this->engine,
            'engine_version' => $this->engineVersion,
            'status' => $this->status,
            'duration_ms' => $this->durationMs,
            'message' => $message === null ? null : substr(trim($message), 0, 500),
            'symbols' => count($this->graph['symbols'] ?? []),
            'relationships' => count($this->graph['relationships'] ?? []),
            'routes' => count($this->graph['routes'] ?? []),
            'findings' => count($this->findings),
        ];
    }
}

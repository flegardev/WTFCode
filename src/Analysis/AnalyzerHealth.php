<?php

declare(strict_types=1);

final class AnalyzerHealth
{
    public function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly ?string $version = null,
    ) {
    }

    /** @return array{status: string, message: string, version: ?string} */
    public function toArray(): array
    {
        return ['status' => $this->status, 'message' => $this->message, 'version' => $this->version];
    }
}

<?php

declare(strict_types=1);

final class GitHubAccessException extends RuntimeException
{
    public function __construct(
        private readonly string $safeMessage,
        string $internalMessage = '',
        public readonly string $reason = 'github_access_failed',
        ?Throwable $previous = null,
    ) {
        parent::__construct($internalMessage !== '' ? $internalMessage : $safeMessage, 0, $previous);
    }

    public function safeMessage(): string
    {
        return $this->safeMessage;
    }
}

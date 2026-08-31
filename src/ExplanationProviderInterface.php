<?php

declare(strict_types=1);

interface ExplanationProviderInterface
{
    public function id(): string;
    public function isAvailable(): bool;
    /** @return array{answer: string, citations: array<int, int>, inferences: array<int, string>} */
    public function explain(EvidencePacket $packet): array;
}

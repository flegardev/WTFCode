<?php

declare(strict_types=1);

final class DeterministicExplanationProvider implements ExplanationProviderInterface
{
    public function id(): string { return 'deterministic'; }
    public function isAvailable(): bool { return true; }

    public function explain(EvidencePacket $packet): array
    {
        $references = $packet->references();
        $citations = array_slice(array_column($references, 'id'), 0, 4);
        $suffix = $citations === [] ? '' : ' ' . implode(' ', array_map(static fn (int $id): string => '[' . $id . ']', $citations));
        return ['answer' => $packet->deterministicAnswer . $suffix, 'citations' => $citations, 'inferences' => []];
    }
}

<?php

declare(strict_types=1);

final class ExplanationManager
{
    /** @return array<string, mixed> */
    public static function answer(int $projectId, string $question): array
    {
        $packet = EvidencePacket::build($projectId, $question);
        $selected = strtolower((string) (getenv('WTF_CODE_EXPLANATION_PROVIDER') ?: 'deterministic'));
        // Customer Q&A stays local until a durable per-customer AI budget and
        // explicit evidence-sharing consent are implemented. Preserve provider
        // adapters for operator tests; environment selection cannot incur spend.
        $provider = new DeterministicExplanationProvider();
        $fallback = $selected !== 'deterministic';
        try {
            if (!$provider->isAvailable()) throw new RuntimeException('Selected explanation provider is unavailable.');
            $explanation = $provider->explain($packet);
        } catch (Throwable $exception) {
            $provider = new DeterministicExplanationProvider();
            $explanation = $provider->explain($packet);
            $fallback = true;
        }
        return $explanation + ['provider' => $provider->id(), 'provider_fallback' => $fallback, 'evidence_packet' => $packet->toArray(), 'evidence' => $packet->displayEvidence, 'trace' => $packet->trace];
    }
}

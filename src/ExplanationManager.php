<?php

declare(strict_types=1);

final class ExplanationManager
{
    /** @return array<string, mixed> */
    public static function answer(int $projectId, string $question): array
    {
        $packet = EvidencePacket::build($projectId, $question);
        $selected = strtolower((string) (getenv('WTF_CODE_EXPLANATION_PROVIDER') ?: 'deterministic'));
        $provider = match ($selected) { 'openai', 'openai-compatible' => new OpenAiCompatibleExplanationProvider(), 'ollama' => new OllamaExplanationProvider(), default => new DeterministicExplanationProvider() };
        $fallback = false;
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

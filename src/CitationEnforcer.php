<?php

declare(strict_types=1);

final class CitationEnforcer
{
    /** @return array{answer: string, citations: array<int, int>, inferences: array<int, string>} */
    public static function normalize(string $content, EvidencePacket $packet): array
    {
        $decoded = json_decode($content, true);
        $answer = is_array($decoded) ? (string) ($decoded['answer'] ?? '') : $content;
        $claimed = is_array($decoded['citations'] ?? null) ? $decoded['citations'] : [];
        preg_match_all('/\[(\d+)\]/', $answer, $matches);
        $claimed = array_merge($claimed, $matches[1] ?? []);
        $validIds = array_fill_keys(array_map('intval', array_column($packet->references(), 'id')), true);
        $citations = array_values(array_unique(array_filter(array_map('intval', $claimed), static fn (int $id): bool => isset($validIds[$id]))));
        $answer = SensitiveDataSanitizer::text(trim($answer));
        if ($answer === '') throw new RuntimeException('Explanation provider returned an empty answer.');
        $inferences = [];
        $sentences = preg_split('/(?<=[.!?])\s+/', $answer) ?: [$answer];
        foreach ($sentences as &$sentence) {
            if (preg_match('/\[\d+\]/', $sentence) || str_starts_with(trim($sentence), 'Inference:')) continue;
            $sentence = 'Inference: ' . ltrim($sentence);
            $inferences[] = $sentence;
        }
        unset($sentence);
        return ['answer' => implode(' ', $sentences), 'citations' => $citations, 'inferences' => $inferences];
    }
}

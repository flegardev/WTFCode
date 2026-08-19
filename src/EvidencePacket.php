<?php

declare(strict_types=1);

final class EvidencePacket
{
    /** @param array<string, mixed> $payload @param array<int, array<string, mixed>> $displayEvidence @param array<string, mixed> $trace */
    private function __construct(
        private readonly array $payload,
        public readonly array $displayEvidence,
        public readonly array $trace,
        public readonly string $deterministicAnswer,
    ) {
    }

    public static function build(int $projectId, string $question): self
    {
        $fallback = ExplanationService::deterministicAnswer($projectId, $question);
        $trace = is_array($fallback['trace'] ?? null) ? $fallback['trace'] : FeatureTracer::trace($projectId, $question);
        $displayEvidence = array_slice(is_array($fallback['evidence'] ?? null) ? $fallback['evidence'] : [], 0, 16);
        $references = [];
        $add = static function (array $item) use (&$references): void {
            $identity = strtolower(implode('|', [(string) ($item['path'] ?? ''), (string) ($item['line'] ?? ''), (string) ($item['kind'] ?? ''), (string) ($item['label'] ?? '')]));
            if ($identity === '|||') return;
            $references[hash('sha256', $identity)] = SensitiveDataSanitizer::scrub($item);
        };
        foreach (array_slice($trace['entry_points'] ?? [], 0, 12) as $route) $add(['kind' => 'route', 'label' => $route['label'], 'path' => $route['path'], 'line' => $route['line'], 'confidence' => $route['confidence']]);
        foreach (array_slice($trace['symbols'] ?? [], 0, 18) as $symbol) $add(['kind' => 'symbol', 'label' => $symbol['type'] . ' ' . $symbol['name'], 'path' => $symbol['path'], 'line' => $symbol['start_line'] ?? 1, 'confidence' => $symbol['confidence']]);
        foreach (array_slice($trace['hops'] ?? [], 0, 24) as $hop) $add(['kind' => 'relationship', 'label' => ($hop['source_name'] ?: 'file scope') . ' ' . $hop['relationship'] . ' ' . $hop['target_name'], 'path' => $hop['evidence_path'], 'line' => $hop['line'] ?? 1, 'confidence' => $hop['confidence']]);
        foreach ($displayEvidence as $file) $add(['kind' => 'file', 'label' => $file['plain_summary'] ?? $file['role_name'] ?? 'Supporting file', 'path' => $file['path'], 'line' => 1, 'confidence' => 'static']);
        $numbered = [];
        foreach (array_slice(array_values($references), 0, 40) as $index => $reference) $numbered[] = ['id' => $index + 1] + $reference;
        $payload = SensitiveDataSanitizer::scrub([
            'question' => trim(substr($question, 0, 500)),
            'symbols' => array_slice(array_map(static fn (array $item): array => ['name' => $item['name'], 'type' => $item['type'], 'path' => $item['path'], 'confidence' => $item['confidence']], $trace['symbols'] ?? []), 0, 20),
            'relationships' => array_slice(array_map(static fn (array $item): array => ['source' => $item['source_name'], 'type' => $item['relationship'], 'target' => $item['target_name'], 'path' => $item['evidence_path'], 'line' => $item['line'], 'confidence' => $item['confidence']], $trace['hops'] ?? []), 0, 30),
            'routes' => array_slice($trace['entry_points'] ?? [], 0, 15),
            'tables' => array_slice($trace['tables'] ?? [], 0, 15),
            'services' => array_slice($trace['services'] ?? [], 0, 15),
            'evidence' => $numbered,
        ]);
        return new self($payload, $displayEvidence, $trace, (string) ($fallback['answer'] ?? 'The scan does not have enough evidence to answer confidently.'));
    }

    /** @return array<string, mixed> */
    public function toArray(): array { return $this->payload; }
    /** @return array<int, array<string, mixed>> */
    public function references(): array { return $this->payload['evidence']; }
}

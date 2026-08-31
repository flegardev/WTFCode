<?php

declare(strict_types=1);

final class OllamaExplanationProvider implements ExplanationProviderInterface
{
    public function id(): string { return 'ollama'; }
    public function isAvailable(): bool { return $this->endpoint() !== null && (bool) ini_get('allow_url_fopen'); }

    public function explain(EvidencePacket $packet): array
    {
        $endpoint = $this->endpoint();
        if ($endpoint === null) throw new RuntimeException('Ollama endpoint must be local.');
        $body = ['model' => getenv('WTF_CODE_OLLAMA_MODEL') ?: 'llama3.2', 'stream' => false, 'format' => 'json', 'messages' => [
            ['role' => 'system', 'content' => 'Use only supplied WTFCode evidence. Return JSON with answer and integer citations. Cite claims as [n]; label unsupported statements Inference:.'],
            ['role' => 'user', 'content' => json_encode($packet->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
        ]];
        $context = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 40, 'ignore_errors' => true, 'max_redirects' => 0, 'header' => 'Content-Type: application/json', 'content' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]]);
        $raw = @file_get_contents($endpoint, false, $context, 0, 1_048_576);
        if (!is_string($raw)) throw new RuntimeException('Ollama request failed.');
        $response = json_decode(SensitiveDataSanitizer::text($raw), true);
        $content = $response['message']['content'] ?? null;
        if (!is_string($content)) throw new RuntimeException('Ollama returned no explanation content.');
        return CitationEnforcer::normalize($content, $packet);
    }

    private function endpoint(): ?string
    {
        $endpoint = getenv('WTF_CODE_OLLAMA_ENDPOINT') ?: 'http://127.0.0.1:11434/api/chat';
        $parts = parse_url($endpoint);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'http' || !in_array(strtolower((string) ($parts['host'] ?? '')), ['127.0.0.1', 'localhost', '::1'], true)) return null;
        return $endpoint;
    }
}

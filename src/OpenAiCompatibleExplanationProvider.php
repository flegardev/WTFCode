<?php

declare(strict_types=1);

final class OpenAiCompatibleExplanationProvider implements ExplanationProviderInterface
{
    public function id(): string { return 'openai-compatible'; }
    public function isAvailable(): bool
    {
        return $this->apiKey() !== '' && $this->endpoint() !== null && (bool) ini_get('allow_url_fopen');
    }

    public function explain(EvidencePacket $packet): array
    {
        if (!$this->isAvailable()) throw new RuntimeException('OpenAI-compatible explanation provider is not configured.');
        $request = [
            'model' => getenv('WTF_CODE_OPENAI_MODEL') ?: 'gpt-4.1-mini', 'temperature' => 0,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => 'Answer only from the WTFCode evidence packet. Return JSON with answer and citations (integer evidence IDs). Cite every supported claim as [n]. Omit unsupported claims or prefix them with Inference:. Never request or reproduce credentials.'],
                ['role' => 'user', 'content' => json_encode($packet->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
            ],
        ];
        $response = $this->post($this->endpoint(), $request, ['Authorization: Bearer ' . $this->apiKey()]);
        $content = $response['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) throw new RuntimeException('OpenAI-compatible provider returned no explanation content.');
        return CitationEnforcer::normalize($content, $packet);
    }

    private function endpoint(): ?string
    {
        $endpoint = getenv('WTF_CODE_OPENAI_ENDPOINT') ?: 'https://api.openai.com/v1/chat/completions';
        $parts = parse_url($endpoint);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) return null;
        return $endpoint;
    }

    private function apiKey(): string { $value = getenv('WTF_CODE_OPENAI_API_KEY'); return is_string($value) ? trim($value) : ''; }

    /** @param array<string, mixed> $body @param array<int, string> $headers @return array<string, mixed> */
    private function post(string $url, array $body, array $headers): array
    {
        $context = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 25, 'ignore_errors' => true, 'max_redirects' => 0, 'header' => implode("\r\n", array_merge(['Content-Type: application/json'], $headers)), 'content' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]]);
        $raw = @file_get_contents($url, false, $context, 0, 1_048_576);
        if (!is_string($raw)) throw new RuntimeException('OpenAI-compatible provider request failed.');
        $decoded = json_decode(SensitiveDataSanitizer::text($raw), true);
        if (!is_array($decoded)) throw new RuntimeException('OpenAI-compatible provider returned invalid JSON.');
        return $decoded;
    }
}

<?php

declare(strict_types=1);

abstract class AbstractLanguageAdapter implements LanguageAdapterInterface
{
    protected function moduleKey(array $file, SymbolGraph $graph): string
    {
        return $graph->addModule($file);
    }

    /** @return array<int, string> */
    protected function lines(array $file): array
    {
        return preg_split('/\R/', (string) ($file['content'] ?? '')) ?: [''];
    }

    protected function excerpt(string $line): string
    {
        $line = trim(preg_replace('/\s+/', ' ', $line) ?? $line);
        return text_length($line) > 300 ? substr($line, 0, 297) . '...' : $line;
    }

    protected function serviceName(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? strtolower($host) : $url;
    }
}

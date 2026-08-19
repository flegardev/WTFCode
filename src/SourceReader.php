<?php

declare(strict_types=1);

final class SourceReader
{
    /** @return array{start: int, end: int, lines: array<int, array{number: int, text: string}>}|null */
    public static function excerpt(array $project, string $relativePath, int $startLine, int $endLine, int $context = 3): ?array
    {
        $root = realpath((string) ($project['local_path'] ?? ''));
        if ($root === false || !is_dir($root)) return null;
        $candidate = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        $rootPrefix = rtrim(strtolower(str_replace('\\', '/', $root)), '/') . '/';
        if ($candidate === false || !is_file($candidate) || !str_starts_with(strtolower(str_replace('\\', '/', $candidate)), $rootPrefix)) return null;
        if (filesize($candidate) > 262144) return null;
        $content = file_get_contents($candidate);
        if ($content === false || str_contains($content, "\0")) return null;
        $sourceLines = preg_split('/\R/', $content) ?: [];
        $from = max(1, $startLine - max(0, $context));
        $to = min(count($sourceLines), max($startLine, $endLine) + max(0, $context));
        if ($to - $from > 120) $to = $from + 120;
        $lines = [];
        for ($number = $from; $number <= $to; $number++) $lines[] = ['number' => $number, 'text' => $sourceLines[$number - 1] ?? ''];
        return ['start' => $from, 'end' => $to, 'lines' => $lines];
    }
}

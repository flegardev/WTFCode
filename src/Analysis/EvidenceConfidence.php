<?php

declare(strict_types=1);

final class EvidenceConfidence
{
    private const RANK = [
        'typescript-semantic' => 100,
        'php-parser' => 96,
        'tree-sitter' => 88,
        'framework-adapter' => 84,
        'ast-grep' => 72,
        'wtfcode-native' => 66,
        'ctags' => 52,
        'ripgrep' => 30,
    ];

    public static function rank(string $provider, bool $documentation = false): int
    {
        if ($documentation) return 10;
        return self::RANK[$provider] ?? 50;
    }

    public static function label(string $provider, string $confidence = 'medium', int $sources = 1, bool $documentation = false): string
    {
        if ($documentation) return 'heuristic';
        if ($sources > 1 && $confidence !== 'low') return 'confirmed';
        $score = self::rank($provider) + match ($confidence) {
            'high' => 8,
            'low' => -12,
            default => 0,
        } + min(12, max(0, $sources - 1) * 6);
        return match (true) {
            $score >= 96 => 'confirmed',
            $score >= 76 => 'strong',
            $score >= 48 => 'likely',
            default => 'heuristic',
        };
    }

    public static function stronger(string $left, string $right): string
    {
        $rank = ['heuristic' => 1, 'likely' => 2, 'strong' => 3, 'confirmed' => 4];
        return ($rank[$right] ?? 0) > ($rank[$left] ?? 0) ? $right : $left;
    }
}

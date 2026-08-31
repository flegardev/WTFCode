<?php

declare(strict_types=1);

final class AnalysisProfile
{
    public const QUICK = 'quick';
    public const DEEP = 'deep';
    public const SECURITY = 'security';
    public const MAXIMUM = 'maximum';

    public static function normalize(string $profile): string
    {
        $profile = strtolower(trim($profile));
        return in_array($profile, [self::QUICK, self::DEEP, self::SECURITY, self::MAXIMUM], true)
            ? $profile
            : self::QUICK;
    }

    public static function includes(string $profile, string $providerId): bool
    {
        $profile = self::normalize($profile);
        $groups = [
            self::QUICK => ['wtfcode-native', 'php-parser', 'tree-sitter', 'typescript-semantic', 'ast-grep'],
            self::DEEP => ['wtfcode-native', 'php-parser', 'tree-sitter', 'typescript-semantic', 'ast-grep', 'ripgrep', 'ctags'],
            self::SECURITY => ['gitleaks', 'semgrep', 'osv-scanner', 'syft', 'grype'],
        ];
        $known = array_values(array_unique(array_merge(...array_values($groups))));
        if (!in_array($providerId, $known, true)) return true;
        if ($profile === self::MAXIMUM) return true;
        return in_array($providerId, $groups[$profile] ?? [], true);
    }

    /** @return array<int, string> */
    public static function providers(string $profile): array
    {
        $all = ['wtfcode-native', 'php-parser', 'tree-sitter', 'typescript-semantic', 'ast-grep', 'ripgrep', 'ctags', 'gitleaks', 'semgrep', 'osv-scanner', 'syft', 'grype'];
        return array_values(array_filter($all, static fn (string $id): bool => self::includes($profile, $id)));
    }
}

<?php

declare(strict_types=1);

final class SensitiveDataSanitizer
{
    private const SECRET_EVIDENCE_KEYS = [
        'rule_id', 'line', 'line_end', 'commit', 'fingerprint', 'masked', 'masked_preview',
        'secret_type', 'engine', 'engine_version', 'confidence', 'context',
    ];

    /** @param array<string, mixed> $finding @return array<string, mixed> */
    public static function finding(array $finding): array
    {
        $finding = self::scrub($finding);
        $type = strtolower((string) ($finding['type'] ?? ''));
        if (!str_contains($type, 'secret') && !str_contains($type, 'credential')) return $finding;
        $evidence = is_array($finding['evidence'] ?? null) ? $finding['evidence'] : [];
        $finding['evidence'] = array_intersect_key($evidence, array_flip(self::SECRET_EVIDENCE_KEYS));
        $finding['evidence']['masked'] = true;
        $finding['evidence']['masked_preview'] = '<redacted>';
        return $finding;
    }

    public static function text(string $value): string
    {
        $patterns = [
            '/\bAKIA[0-9A-Z]{16}\b/',
            '/\bgithub_pat_[A-Za-z0-9_]{20,}\b/',
            '/\bgh[opusr]_[A-Za-z0-9]{20,}\b/',
            '/\bsk-(?:proj-)?[A-Za-z0-9_-]{20,}\b/',
            '/\bxox[baprs]-[A-Za-z0-9-]{20,}\b/',
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z ]*PRIVATE KEY-----/',
            '#\bpostgres(?:ql)?://[^\s"\']+#i',
        ];
        return preg_replace($patterns, '<redacted>', $value) ?? '<redacted>';
    }

    /** @return mixed */
    public static function scrub(mixed $value): mixed
    {
        if (is_string($value)) return self::text($value);
        if (!is_array($value)) return $value;
        $safe = [];
        foreach ($value as $key => $item) {
            $name = strtolower((string) $key);
            if (in_array($name, ['secret', 'raw_secret', 'password', 'db_password', 'database_url', 'private_key', 'authorization', 'token_value'], true)) {
                $safe[$key] = '<redacted>';
                continue;
            }
            $safe[$key] = self::scrub($item);
        }
        return $safe;
    }
}

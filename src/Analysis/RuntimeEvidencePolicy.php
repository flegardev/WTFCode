<?php

declare(strict_types=1);

/** Shared boundary between runtime product evidence and analysis/support material. */
final class RuntimeEvidencePolicy
{
    public static function isRuntimePath(string $path): bool
    {
        $normalized = strtolower(str_replace('\\', '/', $path));
        if (preg_match('#(^|/)(?:tests?|specs?|fixtures?|benchmarks?|docs?|examples?|tools?|reports?|snapshots?|generators?|storage|vendor|node_modules|analysis|analyzers?|detectors?|\.playwright-cli)(/|$)#', $normalized)) return false;
        if (preg_match('#(^|/)config/(?:security|rules?)(/|$)|(^|/)(?:rules?|generated|output|artifacts?)(/|$)#', $normalized)) return false;
        if (preg_match('#(?:^|/)(?:readme|changelog|license)(?:\.[^/]+)?$|\.(?:md|css|scss|html|blade\.php|snap|snapshot|log)$#', $normalized)) return false;
        return true;
    }

    public static function containsTerm(string $text, string $term): bool
    {
        return preg_match('/(?<![\pL\pN])' . preg_quote($term, '/') . '(?![\pL\pN])/iu', $text) === 1;
    }

    /** Runtime behavior requires executable source, not catalogues or declarative metadata. */
    public static function isRuntimeBehaviorPath(string $path): bool
    {
        if (!self::isRuntimePath($path)) return false;
        return preg_match('/\.(?:php|js|jsx|mjs|cjs|ts|tsx|py|rb|go|java|cs|rs|vue|svelte)$/i', str_replace('\\', '/', $path)) === 1;
    }

    public static function isRuntimeSourceEvidence(string $path, string $content): bool
    {
        return self::isRuntimePath($path) && !self::isAnalysisImplementation($content);
    }

    public static function isRuntimeServiceReference(string $path, string $content, int $lineNumber, string $service): bool
    {
        if (!self::isRuntimeBehaviorPath($path) || !self::isRuntimeSourceEvidence($path, $content)) return false;
        $normalizedService = strtolower(trim($service));
        if ($normalizedService === '' || preg_match('/[{}$“”]/u', $normalizedService)) return false;
        if (in_array($normalizedService, ['example.com', 'example.org', 'example.net', 'test', 'https://'], true)) return false;
        $lines = preg_split('/\R/', $content) ?: [];
        $line = (string) ($lines[max(0, $lineNumber - 1)] ?? '');
        $trimmed = trim($line);
        if ($trimmed === '' || preg_match('#^(?://|/\*|\*|\#|<!--)#', $trimmed) || self::isDetectorDefinition($line)) return false;
        $before = implode("\n", array_slice($lines, 0, max(0, $lineNumber - 1)));
        if (preg_match('/\.py$/i', $path) && (substr_count($before, '"""') + substr_count($before, "'''")) % 2 === 1) return false;
        if (!preg_match('/\.py$/i', $path) && substr_count($before, '/*') > substr_count($before, '*/')) return false;
        if (preg_match('/\b(?:fetch|axios(?:\.[a-z]+)?|requests?\.[a-z]+|httpx\.[a-z]+|aiohttp|curl_[a-z]+|new\s+URL|openConnection|Http::(?:get|post|put|patch|delete)|->(?:get|post|put|patch|delete|request|send))\b/i', $line)) return true;
        return preg_match('/(?:\b[A-Za-z_][A-Za-z0-9_]*(?:api|endpoint|base[_-]?(?:url|uri)|webhook|dsn|origin|service[_-]?url|host)[A-Za-z0-9_]*\b|\burl\b)[^\r\n]{0,80}(?:=>|:=|=|:)\s*[\'\"]https?:\/\//i', $line) === 1;
    }

    public static function isAnalysisImplementation(string $content): bool
    {
        $families = 0;
        foreach ([
            '/\b(?:AnalyzerProviderInterface|AnalysisRequest|AnalyzerResult)\b/',
            '/\b(?:addSymbol|addRelationship|addRoute)\s*\(/',
            '/\b(?:symbol_graph|engine_runs|EvidenceFusion)\b/i',
            '/\b(?:discoverFiles|extractSymbols|detectFeatures|matchingPaths)\b/',
            '/\b(?:code_symbols|symbol_relationships|code_routes)\b/',
            '/\b(?:analysis provider|runtime evidence|confidence label)\b/i',
        ] as $pattern) if (preg_match($pattern, $content)) $families++;
        return $families >= 2;
    }

    public static function isDetectorDefinition(string $line): bool
    {
        return preg_match('/\b(?:preg_match(?:_all)?|regex|regexp|[A-Za-z0-9_]*(?:pattern|matcher|detector|signal)[A-Za-z0-9_]*|needles?|rule[_-]?id)\b/i', $line) === 1
            && preg_match('/["\'][^"\']*(?:stripe|supabase|firebase|openai|anthropic|cloudflare|sentry|mongodb|kubernetes)[^"\']*["\']/i', $line) === 1;
    }
}

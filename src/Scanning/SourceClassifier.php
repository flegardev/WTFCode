<?php

declare(strict_types=1);

namespace WTFCode\Scanning;

final class SourceClassifier
{
    private const TEXT_EXTENSIONS = [
        'php', 'js', 'jsx', 'ts', 'tsx', 'py', 'rb', 'go', 'java', 'cs', 'rs',
        'vue', 'svelte', 'json', 'yml', 'yaml', 'toml', 'sql', 'md', 'html',
        'css', 'scss', 'sh', 'env',
    ];

    private const SPECIAL_TEXT_FILES = ['Dockerfile', 'Procfile', 'Makefile', '.env.example'];

    public function isReadableSource(string $relativePath): bool
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

        return in_array($extension, self::TEXT_EXTENSIONS, true)
            || in_array(basename($relativePath), self::SPECIAL_TEXT_FILES, true);
    }

    public function languageFor(string $relativePath): string
    {
        $basename = basename($relativePath);
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        if ($basename === 'Dockerfile') {
            return 'Docker';
        }

        return match ($extension) {
            'ts', 'tsx' => 'TypeScript',
            'js', 'jsx' => 'JavaScript',
            'php' => 'PHP',
            'py' => 'Python',
            'vue' => 'Vue',
            'svelte' => 'Svelte',
            'sql' => 'SQL',
            'json' => 'JSON',
            'yml', 'yaml' => 'YAML',
            'css', 'scss' => 'CSS',
            'html' => 'HTML',
            'md' => 'Markdown',
            'go' => 'Go',
            'rb' => 'Ruby',
            default => $extension === '' ? 'Configuration' : strtoupper($extension),
        };
    }

    public function roleFor(string $path, string $content): string
    {
        $lower = strtolower(str_replace('\\', '/', $path));
        $extension = pathinfo($lower, PATHINFO_EXTENSION);
        $isDocumentation = $extension === 'md';
        $isRuntimeCode = in_array($extension, ['php', 'js', 'jsx', 'ts', 'tsx', 'py', 'rb', 'go', 'java', 'cs'], true);

        if (preg_match('#(^|/)(app|pages)/.+(page|route)\.(tsx?|jsx?)$#', $lower) || str_contains($lower, 'routes/')) {
            return 'route';
        }
        if ((!$isDocumentation && preg_match('/@(app|router)\.(get|post|put|patch|delete)/i', $content)) || str_contains($lower, 'api/')) {
            return 'api endpoint';
        }
        if (str_contains($lower, 'middleware')) {
            return 'middleware';
        }
        if (str_contains($lower, 'auth') || ($isRuntimeCode && preg_match('/(?:password_(?:hash|verify)|session_start\s*\(|\bBearer\s+|supabase\.auth\.(?:signIn|signUp|getUser|onAuthStateChange)|nextauth\/|firebase\.auth\(\))/i', $content))) {
            return 'authentication';
        }
        if (str_contains($lower, 'model') || str_contains($lower, 'schema') || (!$isDocumentation && preg_match('/(CREATE TABLE|prisma|sequelize|mongoose)/i', $content))) {
            return 'data model';
        }
        if (in_array(basename($path), ['package.json', 'composer.json', 'requirements.txt', 'Dockerfile', 'vercel.json', 'docker-compose.yml'], true)) {
            return 'configuration';
        }
        if (preg_match('#(^|/)(components?|ui)/#', $lower)) {
            return 'ui component';
        }

        return 'source';
    }

    public function summaryFor(string $path, string $content): string
    {
        return match ($this->roleFor($path, $content)) {
            'route' => 'This file defines a page or route that users can reach in the application.',
            'api endpoint' => 'This file exposes server-side work that another part of the application can call.',
            'middleware' => 'This file runs before selected requests and can protect, redirect, or reshape them.',
            'authentication' => 'This file participates in identifying users or keeping them signed in.',
            'data model' => 'This file describes, queries, or changes data that the application depends on.',
            'configuration' => 'This file tells tooling or hosting platforms how the project should run.',
            'ui component' => 'This is a reusable piece of the user interface that other screens can include.',
            default => 'This is a source file that contributes application behavior or presentation.',
        };
    }

    /** @return list<string> */
    public function importsFor(string $content, string $relativePath): array
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        $matches = [];
        if (in_array($extension, ['js', 'jsx', 'ts', 'tsx', 'vue', 'svelte'], true)) {
            preg_match_all('/(?:from\s*[\'\"]|import\s*[\'\"]|require\(\s*[\'\"])([^\'\"]+)/', $content, $matches);
        } elseif ($extension === 'php') {
            preg_match_all('/(?:require|require_once|include|include_once)\s*[\(\s]*[\'\"]([^\'\"]+)/', $content, $matches);
        } elseif ($extension === 'py') {
            preg_match_all('/(?:from|import)\s+([A-Za-z0-9_\.\/]+)/', $content, $matches);
        }

        return array_values(array_unique($matches[1] ?? []));
    }

    /** @return list<string> */
    public function symbolsFor(string $content, string $relativePath): array
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        if (!in_array($extension, ['php', 'js', 'jsx', 'ts', 'tsx', 'py', 'vue', 'svelte'], true)) {
            return [];
        }

        $pattern = match ($extension) {
            'php' => '/\b(?:final\s+|abstract\s+)?(?:class|interface|trait|function)\s+([A-Za-z_][A-Za-z0-9_]*)/i',
            'py' => '/\b(?:class|def)\s+([A-Za-z_][A-Za-z0-9_]*)/',
            default => '/\b(?:class|interface|function)\s+([A-Za-z_$][A-Za-z0-9_$]*)|\b(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*(?:async\s*)?\(?[^\n]*?\)?\s*=>/',
        };
        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);
        $symbols = [];
        foreach ($matches as $match) {
            $name = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');
            if ($name !== '') {
                $symbols[] = $name;
            }
            if (count($symbols) >= 16) {
                break;
            }
        }

        return array_values(array_unique($symbols));
    }
}

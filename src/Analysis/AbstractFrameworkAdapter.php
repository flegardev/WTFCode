<?php

declare(strict_types=1);

abstract class AbstractFrameworkAdapter implements FrameworkAdapterInterface
{
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

    protected function hasDependency(array $files, string $manifest, string $dependency): bool
    {
        foreach ($files as $file) {
            if (basename((string) $file['path']) !== $manifest) continue;
            $decoded = json_decode((string) ($file['content'] ?? ''), true);
            if (is_array($decoded)) {
                foreach (['dependencies', 'devDependencies', 'require', 'require-dev'] as $group) {
                    if (isset($decoded[$group][$dependency])) return true;
                }
            }
        }
        return false;
    }

    protected function normalizedWebPath(string $path): string
    {
        $path = preg_replace('#/+#', '/', '/' . ltrim($path, '/')) ?? $path;
        return $path === '' ? '/' : $path;
    }
}

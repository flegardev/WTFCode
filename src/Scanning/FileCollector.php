<?php

declare(strict_types=1);

namespace WTFCode\Scanning;

final class FileCollector
{
    public function __construct(
        private readonly SourceClassifier $classifier = new SourceClassifier(),
        private readonly RepositoryLimits $limits = new RepositoryLimits(),
    ) {}

    /**
     * @return array{files: list<array{path: string, language: string, size: int, lines: int, hash: string, content: string, role: string, summary: string, imports: list<string>, symbols: list<string>}>, limitations: list<string>}
     */
    public function collect(string $root): array
    {
        $resolvedRoot = realpath($root);
        if ($resolvedRoot === false || !is_dir($resolvedRoot)) {
            throw new \RuntimeException('The repository files are no longer available.');
        }

        $files = [];
        $limitations = [];
        $totalBytes = 0;
        $directory = new \RecursiveDirectoryIterator($resolvedRoot, \FilesystemIterator::SKIP_DOTS);
        $ignored = $this->limits->ignoredDirectories;
        $filtered = new \RecursiveCallbackFilterIterator($directory, static function (\SplFileInfo $entry) use ($ignored): bool {
            if ($entry->isLink()) {
                return false;
            }

            return !$entry->isDir() || !in_array($entry->getFilename(), $ignored, true);
        });

        foreach (new \RecursiveIteratorIterator($filtered) as $file) {
            if (count($files) >= $this->limits->maxFiles) {
                $limitations[] = sprintf('The scanner stopped after the %s-file limit.', number_format($this->limits->maxFiles));
                break;
            }
            if (!$file instanceof \SplFileInfo || $file->isLink() || !$file->isFile()) {
                continue;
            }

            $size = (int) $file->getSize();
            if ($size > $this->limits->maxFileBytes || $this->isIgnored($file->getPathname(), $resolvedRoot)) {
                continue;
            }
            if ($totalBytes + $size > $this->limits->maxTotalBytes) {
                $limitations[] = sprintf('The scanner reached its %s MB readable-file limit.', $this->formatMegabytes($this->limits->maxTotalBytes));
                break;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($resolvedRoot, DIRECTORY_SEPARATOR)) + 1));
            if (!$this->classifier->isReadableSource($relative)) {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if ($content === false || str_contains($content, "\0")) {
                continue;
            }

            $files[] = [
                'path' => $relative,
                'language' => $this->classifier->languageFor($relative),
                'size' => $size,
                'lines' => substr_count($content, "\n") + 1,
                'hash' => hash('sha256', $content),
                'content' => $content,
                'role' => $this->classifier->roleFor($relative, $content),
                'summary' => $this->classifier->summaryFor($relative, $content),
                'imports' => $this->classifier->importsFor($content, $relative),
                'symbols' => $this->classifier->symbolsFor($content, $relative),
            ];
            $totalBytes += $size;
        }

        return ['files' => $files, 'limitations' => array_values(array_unique($limitations))];
    }

    private function isIgnored(string $path, string $root): bool
    {
        $relative = substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1);
        $parts = preg_split('#[\\\\/]#', $relative) ?: [];

        return count(array_intersect($parts, $this->limits->ignoredDirectories)) > 0;
    }

    private function formatMegabytes(int $bytes): string
    {
        $megabytes = $bytes / 1_048_576;

        return rtrim(rtrim(number_format($megabytes, 1, '.', ''), '0'), '.');
    }
}

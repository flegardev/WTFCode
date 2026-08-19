<?php

declare(strict_types=1);

final class RepositoryImporter
{
    private const MAX_REPOSITORY_BYTES = 104857600;
    private const GIT_TIMEOUT_SECONDS = 90;

    public static function normalizeGithubUrl(string $url): ?string
    {
        $url = trim($url);
        if (!str_starts_with($url, 'https://')) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower($parts['host'] ?? '') !== 'github.com') {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            return null;
        }
        $path = trim($parts['path'] ?? '', '/');
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:\.git)?$#', $path)) {
            return null;
        }
        return 'https://github.com/' . rtrim($path, '/') . (str_ends_with($path, '.git') ? '' : '.git');
    }

    public static function defaultName(string $repositoryUrl): string
    {
        $path = trim((string) parse_url($repositoryUrl, PHP_URL_PATH), '/');
        return preg_replace('/\.git$/', '', basename($path)) ?: 'Untitled repository';
    }

    public static function clone(int $projectId, string $repositoryUrl): string
    {
        if (self::normalizeGithubUrl($repositoryUrl) !== $repositoryUrl) {
            throw new RuntimeException('Repository URL validation failed.');
        }
        $destination = self::projectPath($projectId);
        if (is_dir($destination)) {
            self::deleteDirectory($destination);
        }
        if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0700, true) && !is_dir(dirname($destination))) {
            throw new RuntimeException('The private repository storage directory could not be created.');
        }

        $result = self::runGit(['-c', 'protocol.file.allow=never', 'clone', '--quiet', '--depth', '100', '--no-tags', '--no-recurse-submodules', $repositoryUrl, $destination]);
        if ($result['exit_code'] !== 0 || !is_dir($destination . DIRECTORY_SEPARATOR . '.git')) {
            self::deleteDirectory($destination);
            throw new RuntimeException('Git could not clone that public repository. Check the URL and make sure Git is installed on the server.');
        }
        if (self::directorySize($destination) > self::MAX_REPOSITORY_BYTES) {
            self::deleteDirectory($destination);
            throw new RuntimeException('This repository is larger than the 100 MB MVP import limit.');
        }
        return $destination;
    }

    public static function projectPath(int $projectId): string
    {
        return __DIR__ . '/../storage/repos/project-' . $projectId;
    }

    private static function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory) || !str_starts_with(str_replace('\\', '/', realpath(dirname($directory)) ?: ''), str_replace('\\', '/', realpath(__DIR__ . '/../storage/repos') ?: ''))) {
            return;
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isLink() || !$item->isDir() ? unlink($item->getPathname()) : rmdir($item->getPathname());
        }
        rmdir($directory);
    }

    /** @return array{exit_code: int, output: array<int, string>} */
    private static function runGit(array $arguments): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $environment = array_merge(getenv() ?: [], ['GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => __DIR__ . '/../storage/.empty-git-config', 'GIT_TERMINAL_PROMPT' => '0']);
        $process = proc_open(array_merge(['git'], $arguments), $descriptors, $pipes, null, $environment, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            return ['exit_code' => 1, 'output' => []];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $startedAt = microtime(true);
        $timedOut = false;
        while (($status = proc_get_status($process))['running']) {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            if (microtime(true) - $startedAt >= self::GIT_TIMEOUT_SECONDS) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }
            usleep(100000);
        }
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        return ['exit_code' => $timedOut ? 1 : $exitCode, 'output' => array_filter(preg_split('/\r?\n/', trim($stdout . "\n" . $stderr)) ?: [])];
    }

    private static function directorySize(string $directory): int
    {
        $size = 0;
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) {
            if (!$item->isLink() && $item->isFile()) {
                $size += $item->getSize();
                if ($size > self::MAX_REPOSITORY_BYTES) {
                    return $size;
                }
            }
        }
        return $size;
    }
}

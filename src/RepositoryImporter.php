<?php

declare(strict_types=1);

final class RepositoryImporter
{
    private const MAX_REPOSITORY_BYTES = 104857600;
    private const GIT_TIMEOUT_SECONDS = 90;
    /** @var array<int, string> */
    private static array $requestCopies = [];

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

        $emptyHooks = self::storageRoot() . DIRECTORY_SEPARATOR . 'empty-hooks';
        if (!is_dir($emptyHooks) && !mkdir($emptyHooks, 0700, true) && !is_dir($emptyHooks)) throw new RuntimeException('Git isolation could not be initialized.');
        $result = self::runGit(['-c', 'protocol.file.allow=never', '-c', 'core.hooksPath=' . $emptyHooks, '-c', 'credential.helper=', '-c', 'core.fsmonitor=false', 'clone', '--quiet', '--depth', '100', '--no-tags', '--no-recurse-submodules', '--config', 'core.hooksPath=' . $emptyHooks, $repositoryUrl, $destination]);
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
        $suffix = self::ephemeral() ? '-' . bin2hex(random_bytes(6)) : '';
        return self::storageRoot() . DIRECTORY_SEPARATOR . 'repos' . DIRECTORY_SEPARATOR . 'project-' . $projectId . $suffix;
    }

    public static function workingCopy(array $project): string
    {
        $projectId = (int) ($project['id'] ?? 0);
        $current = (string) ($project['local_path'] ?? '');
        if ($current !== '' && is_dir($current . DIRECTORY_SEPARATOR . '.git')) return $current;
        if (isset(self::$requestCopies[$projectId]) && is_dir(self::$requestCopies[$projectId] . DIRECTORY_SEPARATOR . '.git')) return self::$requestCopies[$projectId];
        $url = self::normalizeGithubUrl((string) ($project['repository_url'] ?? ''));
        if ($projectId < 1 || $url === null) throw new RuntimeException('The project repository cannot be rehydrated.');
        $path = self::clone($projectId, $url);
        self::$requestCopies[$projectId] = $path;
        if (self::ephemeral()) register_shutdown_function(static fn () => self::cleanup($path));
        return $path;
    }

    public static function cleanup(string $directory): void
    {
        $reposRoot = realpath(self::storageRoot() . DIRECTORY_SEPARATOR . 'repos');
        $parent = realpath(dirname($directory));
        if (!is_dir($directory) || $reposRoot === false || $parent === false || rtrim(str_replace('\\', '/', $parent), '/') !== rtrim(str_replace('\\', '/', $reposRoot), '/')) {
            return;
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isLink() || !$item->isDir() ? unlink($item->getPathname()) : rmdir($item->getPathname());
        }
        rmdir($directory);
    }

    public static function ephemeral(): bool
    {
        return app_config()['environment'] === 'production';
    }

    public static function storageRoot(): string
    {
        return (string) app_config()['storage_path'];
    }

    /** @return array{exit_code: int, output: array<int, string>} */
    private static function runGit(array $arguments): array
    {
        $root = self::storageRoot();
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) return ['exit_code' => 1, 'output' => []];
        $emptyConfig = $root . DIRECTORY_SEPARATOR . '.empty-git-config';
        if (!is_file($emptyConfig)) @file_put_contents($emptyConfig, '', LOCK_EX);
        try {
            $result = (new SafeProcessRunner())->run(new ProcessRunRequest(
                array_merge(['git'], $arguments),
                $root,
                self::GIT_TIMEOUT_SECONDS,
                1_048_576,
                1_048_576,
                ['GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => $emptyConfig, 'GIT_TERMINAL_PROMPT' => '0', 'GIT_OPTIONAL_LOCKS' => '0'],
            ));
            return ['exit_code' => $result->exitCode, 'output' => array_filter(preg_split('/\r?\n/', trim($result->stdout . "\n" . $result->stderr)) ?: [])];
        } catch (Throwable) {
            return ['exit_code' => 1, 'output' => []];
        }
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

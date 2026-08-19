<?php

declare(strict_types=1);

final class ToolDetector
{
    public static function findExecutable(string $name): ?string
    {
        if ($name === '' || str_contains($name, "\0")) return null;
        if (str_contains($name, DIRECTORY_SEPARATOR) || str_contains($name, '/')) {
            $resolved = realpath($name);
            return $resolved !== false && is_file($resolved) ? $resolved : null;
        }
        $localBin = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'bin';
        foreach (PHP_OS_FAMILY === 'Windows' ? [$name, $name . '.exe', $name . '.cmd'] : [$name] as $localName) {
            $candidate = $localBin . DIRECTORY_SEPARATOR . $localName;
            if (is_file($candidate)) return realpath($candidate) ?: $candidate;
        }
        $path = getenv('PATH');
        if (!is_string($path) || $path === '') return null;
        $extensions = [''];
        if (PHP_OS_FAMILY === 'Windows') {
            $pathExt = getenv('PATHEXT');
            $extensions = $pathExt ? array_map('strtolower', explode(';', $pathExt)) : ['.exe', '.cmd', '.bat', '.com'];
            if (pathinfo($name, PATHINFO_EXTENSION) !== '') array_unshift($extensions, '');
        }
        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            $directory = trim($directory, " \t\n\r\0\x0B\"");
            if ($directory === '') continue;
            foreach (array_unique($extensions) as $extension) {
                $candidate = $directory . DIRECTORY_SEPARATOR . $name . $extension;
                if (is_file($candidate)) return realpath($candidate) ?: $candidate;
            }
        }
        return null;
    }
}

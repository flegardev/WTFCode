<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$targets = [
    $projectRoot . DIRECTORY_SEPARATOR . '.php-cs-fixer.php',
    $projectRoot . DIRECTORY_SEPARATOR . 'bootstrap.php',
    $projectRoot . DIRECTORY_SEPARATOR . 'config',
    $projectRoot . DIRECTORY_SEPARATOR . 'public',
    $projectRoot . DIRECTORY_SEPARATOR . 'src',
    $projectRoot . DIRECTORY_SEPARATOR . 'tests',
    $projectRoot . DIRECTORY_SEPARATOR . 'tools',
    $projectRoot . DIRECTORY_SEPARATOR . 'views',
];

$files = [];
foreach ($targets as $target) {
    if (is_file($target) && str_ends_with(strtolower($target), '.php')) {
        $files[] = $target;
        continue;
    }
    if (!is_dir($target)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if ($file->isLink() || !$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $files[] = $file->getPathname();
    }
}

$files = array_values(array_unique($files));
sort($files, SORT_STRING);
$failures = [];

foreach ($files as $file) {
    $descriptorSpec = [
        0 => ['file', 'php://stdin', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(
        [PHP_BINARY, '-l', $file],
        $descriptorSpec,
        $pipes,
        $projectRoot,
        null,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        $failures[] = [$file, 'PHP lint could not be started.'];
        continue;
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        $failures[] = [$file, trim($stdout . PHP_EOL . $stderr)];
    }
}

if ($failures !== []) {
    foreach ($failures as [$file, $message]) {
        $relative = str_replace('\\', '/', substr($file, strlen($projectRoot) + 1));
        fwrite(STDERR, sprintf("PHP syntax failed: %s%s%s%s", $relative, PHP_EOL, $message, PHP_EOL));
    }
    exit(1);
}

fwrite(STDOUT, sprintf("PHP syntax checks passed for %d files.%s", count($files), PHP_EOL));

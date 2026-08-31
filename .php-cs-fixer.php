<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$directories = [__DIR__ . '/tests/Unit'];
foreach (['Application', 'Http', 'Repository', 'Scanning'] as $namespace) {
    $directory = __DIR__ . '/src/' . $namespace;
    if (is_dir($directory)) {
        $directories[] = $directory;
    }
}

$finder = Finder::create()
    ->files()
    ->name('*.php')
    ->in($directories);

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        'array_syntax' => ['syntax' => 'short'],
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
    ])
    ->setFinder($finder);

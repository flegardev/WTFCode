<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function modes_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$nav = (string) file_get_contents(__DIR__ . '/../views/project-nav.php');
foreach (['Understand', 'Change', 'Review', 'Secure', 'Learn'] as $mode) modes_assert(str_contains($nav, "'$mode'"), 'Project navigation must expose ' . $mode . ' mode');
foreach (['understand.php', 'change.php', 'compare.php', 'secure.php', 'learn.php'] as $page) modes_assert(is_file(__DIR__ . '/../public/' . $page), $page . ' must exist');
$understand = (string) file_get_contents(__DIR__ . '/../public/understand.php');
foreach (['Architecture', 'Main features', 'Data', 'Services and deployment'] as $section) modes_assert(str_contains($understand, $section), 'Understand mode must include ' . $section);
$change = (string) file_get_contents(__DIR__ . '/../public/change.php');
foreach (['feature', 'symbol', 'file', 'route', 'table'] as $target) modes_assert(str_contains($change, "'$target'"), 'Change mode must support ' . $target . ' targets');
foreach (['Purpose and dependencies', 'Risks and tests', 'Safe prompt'] as $section) modes_assert(str_contains($change, $section), 'Change mode must include ' . $section);

echo "WTFCode V3 product mode checks passed.\n";

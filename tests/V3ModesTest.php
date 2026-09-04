<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function modes_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$nav = (string) file_get_contents(__DIR__ . '/../views/project-nav.php');
foreach (['Overview', 'Explore', 'Change', 'Security'] as $section) {
    modes_assert(str_contains($nav, "'$section'"), 'Project navigation must expose the ' . $section . ' section');
}
foreach (['project.php', 'map.php', 'change.php', 'secure.php'] as $page) {
    modes_assert(str_contains($nav, "'$page"), 'Primary project navigation must link to ' . $page);
    modes_assert(is_file(__DIR__ . '/../public/' . $page), $page . ' must exist');
}
foreach (['symbols.php', 'routes.php', 'tables.php', 'feature.php', 'files.php'] as $page) {
    modes_assert(str_contains($nav, "'$page"), 'Explore navigation must link to ' . $page);
    modes_assert(is_file(__DIR__ . '/../public/' . $page), $page . ' must exist');
}
modes_assert(str_contains($nav, 'data-ask-open') && str_contains($nav, 'Ask codebase'), 'Project navigation must expose the persistent Ask control');
$askPanel = (string) file_get_contents(__DIR__ . '/../views/ask-panel.php');
$askEndpoint = (string) file_get_contents(__DIR__ . '/../public/ask.php');
modes_assert(str_contains($askPanel, 'data-ask-dialog') && str_contains($askPanel, 'data-ask-form'), 'Persistent Ask must provide an accessible dialog and form');
modes_assert(str_contains($askEndpoint, 'Project::findForUser') && str_contains($askEndpoint, 'verify_csrf'), 'Ask requests must remain owner-scoped and CSRF-protected');
foreach (['understand.php', 'compare.php', 'learn.php'] as $page) {
    modes_assert(is_file(__DIR__ . '/../public/' . $page), $page . ' contextual tool must exist');
}
$understand = (string) file_get_contents(__DIR__ . '/../public/understand.php');
foreach (['Architecture', 'Main features', 'Data', 'Services and deployment'] as $section) {
    modes_assert(str_contains($understand, $section), 'Understand mode must include ' . $section);
}
modes_assert(str_contains($understand, "=== 'external_service'"), 'Understand must not let environment keys displace runtime services');
$change = (string) file_get_contents(__DIR__ . '/../public/change.php');
foreach (['feature', 'symbol', 'file', 'route', 'table'] as $target) {
    modes_assert(str_contains($change, "'$target'"), 'Change mode must support ' . $target . ' targets');
}
foreach (['What will this change break?', 'Critical impact', 'Likely tests', 'Recommended before merge', 'Implementation prompt'] as $section) {
    modes_assert(str_contains($change, $section), 'Change workbench must include ' . $section);
}
modes_assert(str_contains($change, "PromptSafetyService::build"), 'Change workbench must build a bounded implementation prompt');
$learn = (string) file_get_contents(__DIR__ . '/../public/learn.php');
modes_assert(str_contains($learn, 'Open evidence') && str_contains((string) file_get_contents(__DIR__ . '/../src/ExplorationService.php'), "'href'"), 'Learn chapters must link back to supporting project evidence');
$routes = (string) file_get_contents(__DIR__ . '/../public/routes.php');
foreach (['Inputs:', 'Responses:', 'External:', 'Control:'] as $detail) {
    modes_assert(str_contains($routes, $detail), 'Route cards must expose ' . $detail . ' intelligence when present');
}

echo "WTFCode V3 product mode checks passed.\n";

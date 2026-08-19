<?php

declare(strict_types=1);

$activeProjectSection = $activeProjectSection ?? '';
$modeLinks = [
    'understand' => ['understand.php?id=' . (int) $project['id'], 'Understand'],
    'change' => ['change.php?id=' . (int) $project['id'], 'Change'],
    'review' => ['compare.php?id=' . (int) $project['id'], 'Review'],
    'secure' => ['secure.php?id=' . (int) $project['id'], 'Secure'],
    'learn' => ['learn.php?id=' . (int) $project['id'], 'Learn'],
];
$projectLinks = [
    'overview' => ['project.php?id=' . (int) $project['id'], 'Overview'],
    'graph' => ['map.php?id=' . (int) $project['id'], 'Symbol graph'],
    'symbols' => ['symbols.php?id=' . (int) $project['id'], 'Symbols'],
    'routes' => ['routes.php?id=' . (int) $project['id'], 'Routes'],
    'data' => ['tables.php?id=' . (int) $project['id'], 'Data and services'],
    'trace' => ['feature.php?id=' . (int) $project['id'], 'Trace'],
    'files' => ['files.php?id=' . (int) $project['id'], 'Files'],
];
?>
<nav class="project-nav project-nav-v2 mode-nav" aria-label="Product modes">
    <?php foreach ($modeLinks as $section => [$href, $label]): ?>
        <a class="<?= $activeProjectSection === $section ? 'is-active' : '' ?>" href="<?= e(url($href)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<nav class="project-nav project-nav-v2" aria-label="Project analysis">
    <?php foreach ($projectLinks as $section => [$href, $label]): ?>
        <a class="<?= $activeProjectSection === $section ? 'is-active' : '' ?>" href="<?= e(url($href)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

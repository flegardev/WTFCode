<?php

declare(strict_types=1);

$activeProjectSection = $activeProjectSection ?? '';
$areaForSection = [
    'overview' => 'overview', 'understand' => 'overview', 'learn' => 'overview',
    'graph' => 'explore', 'symbols' => 'explore', 'routes' => 'explore', 'data' => 'explore', 'trace' => 'explore', 'files' => 'explore',
    'change' => 'change', 'review' => 'change', 'prompt' => 'change',
    'secure' => 'security', 'analyzers' => 'security',
];
$currentArea = $areaForSection[$activeProjectSection] ?? '';
$primaryLinks = [
    'overview' => ['project.php?id=' . (int) $project['id'], 'Overview'],
    'explore' => ['map.php?id=' . (int) $project['id'], 'Explore'],
    'change' => ['change.php?id=' . (int) $project['id'], 'Change'],
    'security' => ['secure.php?id=' . (int) $project['id'], 'Security'],
];
$contextLinks = [
    'overview' => [
        'overview' => ['project.php?id=' . (int) $project['id'], 'Summary'],
        'understand' => ['understand.php?id=' . (int) $project['id'], 'Architecture brief'],
        'learn' => ['learn.php?id=' . (int) $project['id'], 'Exploration history'],
    ],
    'explore' => [
        'graph' => ['map.php?id=' . (int) $project['id'], 'Graph'],
        'symbols' => ['symbols.php?id=' . (int) $project['id'], 'Symbols'],
        'routes' => ['routes.php?id=' . (int) $project['id'], 'Routes'],
        'data' => ['tables.php?id=' . (int) $project['id'], 'Data'],
        'trace' => ['feature.php?id=' . (int) $project['id'], 'Trace'],
        'files' => ['files.php?id=' . (int) $project['id'], 'Files'],
    ],
    'change' => [
        'change' => ['change.php?id=' . (int) $project['id'], 'Plan change'],
        'review' => ['compare.php?id=' . (int) $project['id'], 'Review Git diff'],
        'prompt' => ['prompt.php?id=' . (int) $project['id'], 'Safe prompt'],
    ],
    'security' => [
        'secure' => ['secure.php?id=' . (int) $project['id'], 'Findings'],
        'analyzers' => ['analyzers.php?id=' . (int) $project['id'], 'Scan details'],
    ],
];
?>
<div class="project-navigation">
    <div class="project-primary-row">
        <nav class="project-primary-nav" aria-label="Project sections">
            <?php foreach ($primaryLinks as $area => [$href, $label]): ?>
                <a class="<?= $currentArea === $area ? 'is-active' : '' ?>" href="<?= e(url($href)) ?>"<?= $currentArea === $area ? ' aria-current="location"' : '' ?>><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <a class="project-nav-ask" href="<?= e(url('ask.php?id=' . (int) $project['id'])) ?>" data-ask-open aria-haspopup="dialog" aria-controls="ask-codebase-panel">
            <span>Ask codebase</span><kbd data-shortcut-label>Ctrl K</kbd>
        </a>
    </div>
    <?php if ($currentArea !== '' && isset($contextLinks[$currentArea])): ?>
        <nav class="project-context-nav" aria-label="<?= e(ucfirst($currentArea)) ?> tools">
            <?php foreach ($contextLinks[$currentArea] as $section => [$href, $label]): ?>
                <a class="<?= $activeProjectSection === $section ? 'is-active' : '' ?>" href="<?= e(url($href)) ?>"<?= $activeProjectSection === $section ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
</div>

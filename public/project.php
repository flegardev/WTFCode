<?php

declare(strict_types=1);

use WTFCode\Http\Controller\ProjectOverviewController;

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
$projectId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$view = $projectId === false || $projectId === null ? null : (new ProjectOverviewController())->show(Auth::id(), (int) $projectId);
if ($view === null) { http_response_code(404); exit('Project not found.'); }
$project = $view['project'];
if (is_post()) {
    verify_csrf();
    $profile = post_string('profile');
    $error = Project::queueRescan($project, Auth::id(), $profile);
    flash($error === null ? 'success' : 'error', $error ?? 'Repository analysis queued. You can keep browsing while it runs.');
    redirect('project.php?id=' . (int) $project['id']);
}
$architecture = $view['architecture'];
$findings = $view['findings'];
$files = $view['files'];
$stack = $view['stack'];
$progress = $view['progress'];
$symbolCounts = $view['symbol_counts'];
$externalServices = $view['external_services'];
$latestScan = $view['latest_scan'];
$latestJob = $view['latest_job'];
$reviewFindings = array_values(array_filter($findings, static fn (array $finding): bool => in_array($finding['severity'] ?? '', ['risk', 'attention'], true)));
$riskCount = count(array_filter($reviewFindings, static fn (array $finding): bool => ($finding['severity'] ?? '') === 'risk'));
$attentionCount = count($reviewFindings) - $riskCount;
$architectureCount = count($architecture['nodes']);
$routeCount = (int) ($symbolCounts['route_count'] ?? 0);
$serviceCount = count($externalServices);
$conclusionParts = [];
if ($architectureCount > 0) $conclusionParts[] = $architectureCount . ' detected system' . ($architectureCount === 1 ? '' : 's');
if ($routeCount > 0) $conclusionParts[] = $routeCount . ' HTTP route' . ($routeCount === 1 ? '' : 's');
if ($serviceCount > 0) $conclusionParts[] = $serviceCount . ' external service' . ($serviceCount === 1 ? '' : 's');
if (count($conclusionParts) > 1) {
    $lastConclusionPart = array_pop($conclusionParts);
    $repositoryConclusion = 'Static analysis found ' . implode(', ', $conclusionParts) . ', and ' . $lastConclusionPart . '.';
} elseif ($conclusionParts !== []) {
    $repositoryConclusion = 'Static analysis found ' . $conclusionParts[0] . '.';
} else {
    $repositoryConclusion = (string) ($project['overview'] ?? 'The first scan is still being prepared.');
}
$lastAnalyzed = $project['last_scan_at'] ?? $latestScan['created_at'] ?? $latestJob['finished_at'] ?? null;
$lastAnalyzedTimestamp = is_string($lastAnalyzed) ? strtotime($lastAnalyzed) : false;
$lastAnalyzedLabel = $lastAnalyzedTimestamp === false ? 'Analysis time unavailable' : 'Analyzed ' . date('M j, Y \a\t H:i', $lastAnalyzedTimestamp);
$overviewGraph = [
    'nodes' => array_map(static fn (array $node): array => [
        'key' => (string) ($node['node_key'] ?? ''),
        'type' => (string) ($node['node_type'] ?? 'system'),
        'label' => (string) ($node['label'] ?? 'Detected system'),
        'explanation' => (string) ($node['plain_explanation'] ?? ''),
    ], $architecture['nodes']),
    'edges' => array_map(static fn (array $edge): array => [
        'from' => (string) ($edge['from_key'] ?? ''),
        'to' => (string) ($edge['to_key'] ?? ''),
        'relationship' => (string) ($edge['relationship_label'] ?? 'connects to'),
    ], $architecture['edges']),
];
$jobSteps = is_array($latestJob['steps'] ?? null) ? $latestJob['steps'] : [];
$completedSteps = count(array_filter($jobSteps, static fn (array $step): bool => ($step['state'] ?? '') === 'completed'));
$partialSteps = count(array_filter($jobSteps, static fn (array $step): bool => ($step['state'] ?? '') === 'partial'));
$failedSteps = count(array_filter($jobSteps, static fn (array $step): bool => ($step['state'] ?? '') === 'failed'));
if ($jobSteps !== []) {
    $jobSummary = $completedSteps . ' of ' . count($jobSteps) . ' analyzers completed';
    if ($partialSteps > 0) $jobSummary .= ', ' . $partialSteps . ' partial';
    if ($failedSteps > 0) $jobSummary .= ', ' . $failedSteps . ' failed';
    $jobSummary .= '.';
} elseif ($latestJob !== null) {
    $jobSummary = 'Scan ' . str_replace('_', ' ', (string) $latestJob['state']) . '.';
} elseif ($latestScan !== null) {
    $jobSummary = 'Scan complete. Detailed analyzer status is not available for this run.';
} else {
    $jobSummary = 'Analysis ' . str_replace('_', ' ', (string) $project['status']) . '.';
}
$beginnerSummary = \WTFCode\Application\ProjectUnderstanding::summary($architecture['nodes'], SymbolRepository::routes((int) $project['id']), $stack);
$pageTitle = $project['name'];
$activePage = 'dashboard';
$activeProjectSection = 'overview';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell v2-shell project-overview-page">
    <div class="breadcrumb"><a href="<?= e(url('dashboard.php')) ?>">Projects</a><span>/</span><span><?= e($project['name']) ?></span></div>
    <header class="project-outcome-header">
        <div class="project-outcome-copy">
            <div class="project-state-line"><span class="status <?= e($project['status']) ?>"><?= e($project['status']) ?></span><span><?= e($lastAnalyzedLabel) ?></span></div>
            <h1><?= e($project['name']) ?></h1>
            <p class="project-conclusion">Your application, explained from its source.</p>

        </div>
        <details class="rescan-disclosure"><summary>Rescan project</summary><form method="post" class="rescan-profile project-rescan">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <label><span>Analysis profile</span><select name="profile"><?php foreach ((\WTFCode\Application\PilotAccess::mode() === 'allowlist' ? [AnalysisProfile::QUICK => 'Quick'] : [AnalysisProfile::QUICK => 'Quick', AnalysisProfile::DEEP => 'Deep', AnalysisProfile::SECURITY => 'Security', AnalysisProfile::MAXIMUM => 'Maximum']) as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
            <button class="button button-quiet" type="submit">Rescan</button>
        </form></details>
    </header>
    <?php require __DIR__ . '/../views/project-nav.php'; ?>
    <details class="scan-disclosure"><summary>Latest scan · <?= e(str_replace('_', ' ', (string) ($latestJob['state'] ?? $project['status']))) ?></summary><section class="scan-summary-line" aria-label="Latest scan status" data-scan-progress data-evidence-loaded="<?= $latestScan === null ? 'false' : 'true' ?>" data-scan-project="<?= (int) $project['id'] ?>" data-scan-endpoint="<?= e(url('scan-status.php?id=' . (int) $project['id'])) ?>">
        <span class="scan-state <?= e((string) ($latestJob['state'] ?? $project['status'])) ?>" data-scan-state><?= e(ucfirst((string) ($latestJob['state'] ?? $project['status']))) ?></span>
        <strong data-scan-stage><?= e($jobSummary) ?></strong>
        <?php $latestJobTerminal = $latestJob !== null && in_array((string) ($latestJob['state'] ?? ''), ['completed', 'partial', 'failed'], true); ?>
        <span class="scan-progress-count" data-scan-count><?= $latestJob !== null && !$latestJobTerminal && (int) ($latestJob['progress_total'] ?? 0) > 0 ? (int) ($latestJob['progress_current'] ?? 0) . ' of ' . (int) $latestJob['progress_total'] . ' scan stages' : '' ?></span>
        <progress data-scan-meter max="<?= max(1, (int) ($latestJob['progress_total'] ?? 1)) ?>" value="<?= max(0, (int) ($latestJob['progress_current'] ?? 0)) ?>"<?= $latestJob === null || $latestJobTerminal || (int) ($latestJob['progress_total'] ?? 0) < 1 ? ' hidden' : '' ?>>Scan-stage progress</progress>
        <span class="scan-progress-error" data-scan-error role="alert" hidden></span>
        <a href="<?= e(url('project.php?id=' . (int) $project['id'])) ?>" data-scan-reload hidden>Refresh evidence</a>
        <a href="<?= e(url('analyzers.php?id=' . (int) $project['id'])) ?>">Scan details</a>
    </section></details>
    <?php if ($latestScan === null): ?>
        <section class="empty-panel" aria-live="polite"><span class="empty-mark" aria-hidden="true">{ }</span><h2><?= ($project['status'] ?? '') === 'failed' ? 'This scan needs another try.' : 'Getting to know your codebase.' ?></h2><p><?= ($project['status'] ?? '') === 'failed' ? 'The scan did not finish. Check the URL and try Rescan above. Your account and project remain available.' : 'Your repository is queued for a background worker. Analysis will appear here when it finishes. There are no findings to assess yet.' ?></p><p>You can return to your projects while the scan runs.</p><a class="button button-secondary" href="<?= e(url('dashboard.php')) ?>">Back to projects</a></section>
    <?php else: ?>
    <section class="overview-section project-plain-summary"><p class="eyebrow">01 / The big picture</p><h2>What did I build?</h2><p class="summary-lead"><?= e($beginnerSummary) ?></p><p class="subtle">The source shows how parts are arranged. It does not establish the product’s business purpose or prove that it runs correctly.</p><a href="<?= e(url('understand.php?id=' . (int) $project['id'])) ?>">Inspect the supporting evidence →</a></section>
    <a class="project-ask-bar" href="<?= e(url('ask.php?id=' . (int) $project['id'])) ?>" data-ask-open aria-haspopup="dialog" aria-controls="ask-codebase-panel"><span class="project-ask-mark"><?= ui_icon('message-circle') ?></span><span><strong>Something you want to understand?</strong><small>Ask about login, data, a file, or your next change.</small></span><kbd data-shortcut-label>Ctrl K</kbd></a>
    <section class="overview-workspace">
        <?php require __DIR__ . '/../views/architecture-diagram.php'; ?>
        <aside class="overview-section what-matters" id="understand">
            <header><div><p class="eyebrow">03 / A closer look</p><h2>What should I know?</h2><p>These items need human review. They are not confirmed failures.</p></div><a href="<?= e(url('secure.php?id=' . (int) $project['id'])) ?>">Review all</a></header>
            <?php if ($reviewFindings === []): ?><div class="what-matters-empty"><strong>No high-signal review lead was found.</strong><p>Static analysis can still miss runtime behavior. Review changes and run the closest tests.</p></div>
            <?php else: ?><div class="priority-finding-list"><?php foreach (array_slice($reviewFindings, 0, 1) as $finding): ?><?php $related = $finding['file_path'] ? Project::fileByPath((int) $project['id'], (string) $finding['file_path']) : null; ?><article class="priority-finding <?= e($finding['severity']) ?>"><span><?= 'Needs review' ?></span><h3><?= e($finding['title']) ?></h3><p><?= e($finding['plain_explanation']) ?></p><?php if ($related !== null): ?><a href="<?= e(url('file.php?project=' . (int) $project['id'] . '&id=' . (int) $related['id'])) ?>">Open evidence</a><?php endif; ?></article><?php endforeach; ?></div><?php endif; ?>
        </aside>
    </section>
    <section class="next-steps"><p class="eyebrow">04 / Move forward</p><h2>What should I do next?</h2><div class="next-step-grid"><a href="<?= e(url('ask.php?id=' . (int) $project['id'])) ?>" data-ask-open><strong>Ask about your code <span>↗</span></strong><small>Get an answer with source references.</small></a><a href="<?= e(url('change.php?id=' . (int) $project['id'])) ?>"><strong>Check a planned change <span>→</span></strong><small>See connected code before you edit.</small></a><a href="<?= e(url('files.php?id=' . (int) $project['id'])) ?>"><strong>Inspect source evidence <span>→</span></strong><small>Read explanations of individual files.</small></a></div></section>
    <details class="advanced-section"><summary>Technical details · files, counts and scan context</summary>
            <?php if ($stack !== []): ?><div class="stack-line"><span>Detected stack</span><?php foreach ($stack as $item): ?><b><?= e($item) ?></b><?php endforeach; ?></div><?php endif; ?>
    <dl class="overview-facts" aria-label="Source scan counts"><div><dt>Detected systems</dt><dd><?= $architectureCount ?></dd></div><div><dt>HTTP routes</dt><dd><?= $routeCount ?></dd></div><div><dt>External services</dt><dd><?= $serviceCount ?></dd></div><div><dt>Review themes</dt><dd><?= count($reviewFindings) ?></dd></div></dl><p><?= e($repositoryConclusion) ?> System connections can be inferred; inspect each edge before relying on it.</p>
    <section class="overview-section impact-files" id="files"><header><div><h2>High-impact files</h2><p>Files ordered by confirmed direct dependents.</p></div><a href="<?= e(url('files.php?id=' . (int) $project['id'])) ?>">Browse all files</a></header><?php if ($files === []): ?><div class="empty-inline">No readable source files were stored from this scan.</div><?php else: ?><div class="file-list"><?php foreach ($files as $file): ?><a href="<?= e(url('file.php?project=' . (int) $project['id'] . '&id=' . (int) $file['id'])) ?>"><div><span><?= e($file['role_name']) ?></span><strong><?= e($file['path']) ?></strong></div><div class="file-impact"><b><?= (int) $file['dependent_count'] ?></b><span>direct dependent<?= (int) $file['dependent_count'] === 1 ? '' : 's' ?></span></div></a><?php endforeach; ?></div><?php endif; ?></section>
    <a class="exploration-progress-link" href="<?= e(url('learn.php?id=' . (int) $project['id'])) ?>"><span>Exploration progress</span><strong><?= (int) ($progress['percentage'] ?? 0) ?>%</strong><small>Based on project evidence you have opened, not analyzer understanding.</small></a>
    </details>
    <?php endif; ?>
</section>
<?php if ($architecture['nodes'] !== []): ?><script src="<?= e(url('assets/lib/cytoscape-3.34.1.min.js')) ?>" defer></script><script src="<?= e(url('assets/js/overview-graph.js')) ?>" defer></script><?php endif; ?>
<script src="<?= e(url('assets/js/scan-progress.js')) ?>" defer></script>
<?php require __DIR__ . '/../views/footer.php'; ?>

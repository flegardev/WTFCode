<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
$projectId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$project = $projectId === false || $projectId === null ? null : Project::findForUser((int) $projectId, Auth::id());
if ($project === null) { http_response_code(404); exit('Project not found.'); }
if (is_post()) {
    verify_csrf();
    $error = Project::rescan($project, Auth::id(), post_string('profile'));
    flash($error === null ? 'success' : 'error', $error ?? 'Repository analysis refreshed.');
    redirect('project.php?id=' . (int) $project['id']);
}
$architecture = Project::architecture((int) $project['id']);
$findings = Project::findings((int) $project['id']);
$files = Project::files((int) $project['id'], '', 18);
$stack = json_decode((string) $project['stack_json'], true) ?: [];
$progress = ExplorationService::progress(Auth::id(), (int) $project['id']);
$symbolCounts = SymbolRepository::counts((int) $project['id']);
$latestScan = SymbolRepository::latestScan((int) $project['id']);
$latestJob = AnalysisJobStore::latest((int) $project['id']);
$pageTitle = $project['name'];
$activePage = 'dashboard';
$activeProjectSection = 'overview';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell">
    <div class="breadcrumb"><a href="<?= e(url('dashboard.php')) ?>">Projects</a><span>/</span><span><?= e($project['name']) ?></span></div>
    <div class="project-heading">
        <div><span class="status <?= e($project['status']) ?>"><?= e($project['status']) ?></span><h1><?= e($project['name']) ?></h1><p><?= e($project['overview'] ?? 'The first scan is still being prepared.') ?></p></div>
        <div class="heading-actions"><a class="button button-secondary" href="<?= e(url('compare.php?id=' . (int) $project['id'])) ?>">Review Git changes</a><form method="post" class="rescan-profile"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label><span class="sr-only">Analysis profile</span><select name="profile"><?php foreach ([AnalysisProfile::QUICK => 'Quick', AnalysisProfile::DEEP => 'Deep', AnalysisProfile::SECURITY => 'Security', AnalysisProfile::MAXIMUM => 'Maximum'] as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label><button class="button button-quiet" type="submit">Rescan</button></form></div>
    </div>
    <?php if ($stack !== []): ?><div class="stack-line"><span>Detected stack</span><?php foreach ($stack as $item): ?><b><?= e($item) ?></b><?php endforeach; ?></div><?php endif; ?>
    <?php require __DIR__ . '/../views/project-nav.php'; ?>
    <section class="v2-metrics" aria-label="V2 analysis summary">
        <div><span>Analysis</span><strong><?= e($latestScan['analysis_version'] ?? 'v1') ?></strong></div>
        <div><span>Symbols</span><strong><?= (int) ($symbolCounts['symbol_count'] ?? 0) ?></strong></div>
        <div><span>Routes</span><strong><?= (int) ($symbolCounts['route_count'] ?? 0) ?></strong></div>
        <div><span>Data refs</span><strong><?= (int) ($symbolCounts['table_count'] ?? 0) ?></strong></div>
        <div><span>Learned</span><strong><?= $progress['percentage'] ?>%</strong></div>
    </section>
    <?php if ($latestJob !== null): ?><section class="node-evidence-strip"><strong>Latest <?= e($latestJob['analysis_profile']) ?> job: <?= e($latestJob['state']) ?></strong><?php foreach ($latestJob['steps'] as $step): ?><a href="<?= e(url('analyzers.php?id=' . (int) $project['id'])) ?>"><?= e($step['provider_id']) ?> · <?= e($step['state']) ?><?= $step['cache_hit'] ? ' · cached' : '' ?><?= $step['incremental'] ? ' · incremental' : '' ?></a><?php endforeach; ?></section><?php endif; ?>
    <section class="project-tools" aria-label="Project tools"><a href="<?= e(url('understand.php?id=' . (int) $project['id'])) ?>"><strong>Understand this app</strong><span>Architecture, features, data, deployment</span></a><a href="<?= e(url('change.php?id=' . (int) $project['id'])) ?>"><strong>Plan a safe change</strong><span>Blast radius, risks, tests, prompt</span></a><a href="<?= e(url('learn.php?id=' . (int) $project['id'])) ?>"><strong>Learn this app</strong><span><?= $progress['percentage'] ?>% explored</span></a></section>
    <section class="content-grid">
        <article class="panel map-panel" id="architecture"><div class="section-row"><div><p class="landing-kicker">Architecture map</p><h2>What the scanner can support</h2></div><p>Cards are confirmed from matching repository evidence. Connections are explicitly labelled when inferred.</p></div>
            <?php if ($architecture['nodes'] === []): ?><div class="empty-inline">The scan did not find enough strong integration evidence to draw an architecture map yet.</div>
            <?php else: ?><div class="architecture-nodes"><?php foreach ($architecture['nodes'] as $node): ?><?php $architectureNode = $node; $architectureHref = url('feature.php?id=' . (int) $project['id'] . '&feature=' . urlencode($node['node_key'])); require __DIR__ . '/../views/architecture-node.php'; ?><?php endforeach; ?></div><?php if ($architecture['edges'] !== []): ?><div class="edge-list"><?php foreach ($architecture['edges'] as $edge): ?><p><strong><?= e($edge['from_label']) ?></strong><span><?= e($edge['relationship_label']) ?></span><strong><?= e($edge['to_label']) ?></strong><em>inferred</em></p><?php endforeach; ?></div><?php endif; ?><?php endif; ?>
        </article>
        <aside class="panel understand-panel" id="understand"><p class="landing-kicker">Before your next prompt</p><h2>Things to understand</h2><?php if ($findings === []): ?><p class="muted-copy">No high-signal risks were found by the current rules. That does not replace code review or tests.</p><?php else: ?><div class="finding-list"><?php foreach ($findings as $finding): ?><?php $related = $finding['file_path'] ? Project::fileByPath((int) $project['id'], (string) $finding['file_path']) : null; ?><article class="finding <?= e($finding['severity']) ?>"><span><?= e($finding['severity']) ?></span><h3><?= e($finding['title']) ?></h3><p><?= e($finding['plain_explanation']) ?></p><?php if ($related !== null): ?><a href="<?= e(url('file.php?project=' . (int) $project['id'] . '&id=' . (int) $related['id'])) ?>">Open related file</a><?php endif; ?></article><?php endforeach; ?></div><?php endif; ?></aside>
    </section>
    <section class="panel files-panel" id="files"><div class="section-row"><div><p class="landing-kicker">Your codebase</p><h2>High-impact files</h2></div><a href="<?= e(url('files.php?id=' . (int) $project['id'])) ?>">Browse every file</a></div><?php if ($files === []): ?><div class="empty-inline">No readable source files were stored from this scan.</div><?php else: ?><div class="file-list"><?php foreach ($files as $file): ?><a href="<?= e(url('file.php?project=' . (int) $project['id'] . '&id=' . (int) $file['id'])) ?>"><div><span><?= e($file['role_name']) ?></span><strong><?= e($file['path']) ?></strong></div><div class="file-impact"><b><?= (int) $file['dependent_count'] ?></b><span>direct dependent<?= (int) $file['dependent_count'] === 1 ? '' : 's' ?></span></div></a><?php endforeach; ?></div><?php endif; ?></section>
</section>
<?php require __DIR__ . '/../views/footer.php'; ?>

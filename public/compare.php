<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
$projectId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$project = $projectId === false || $projectId === null ? null : Project::findForUser((int) $projectId, Auth::id());
if ($project === null) { http_response_code(404); exit('Project not found.'); }
$commits = GitDiffService::commits($project);
$result = null;
$from = '';
$to = '';
$intended = '';
$guardNotice = null;
if (is_post()) {
    verify_csrf();
    $action = post_string('action');
    $intended = post_string('intended_change');
    if (in_array($action, ['capture_before', 'capture_after'], true)) {
        $phase = $action === 'capture_before' ? 'before' : 'after';
        ChangeGuardService::capture((int) $project['id'], Auth::id(), $phase, post_string('label'), $intended);
        $guardNotice = ucfirst($phase) . ' snapshot captured from the latest normalized scan.';
    } else {
        $from = post_string('from');
        $to = post_string('to');
        $result = GitDiffService::compare($project, $from, $to, $intended);
    }
}
$guardComparison = ChangeGuardService::latestComparison((int) $project['id'], Auth::id());
$pageTitle = 'Compare changes';
$activePage = 'dashboard';
$activeProjectSection = 'review';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell narrow"><div class="breadcrumb"><a href="<?= e(url('project.php?id=' . (int) $project['id'])) ?>"><?= e($project['name']) ?></a><span>/</span><span>Compare changes</span></div><div class="project-heading compact"><div><p class="landing-kicker">Change-set review</p><h1>What changed, structurally?</h1><p>Compare Git text with symbols, routes, schema, dependencies, security boundaries, environment keys, and architecture evidence. Authorship remains unknown.</p></div></div><?php require __DIR__ . '/../views/project-nav.php'; ?>
<section class="compare-card"><?php if ($commits === []): ?><div class="empty-inline">Commit history was not available. The repository may have been cloned without accessible Git history.</div><?php else: ?><form method="post" class="compare-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="compare"><label>Earlier commit<select name="from" required><option value="">Choose a commit</option><?php foreach ($commits as $commit): ?><option value="<?= e($commit['sha']) ?>" <?= $from === $commit['sha'] ? 'selected' : '' ?>><?= e(substr($commit['sha'], 0, 8) . ' - ' . $commit['message']) ?></option><?php endforeach; ?></select></label><label>Later commit<select name="to" required><option value="">Choose a commit</option><?php foreach ($commits as $commit): ?><option value="<?= e($commit['sha']) ?>" <?= $to === $commit['sha'] ? 'selected' : '' ?>><?= e(substr($commit['sha'], 0, 8) . ' - ' . $commit['message']) ?></option><?php endforeach; ?></select></label><label>Intended change <span>Optional</span><textarea name="intended_change" maxlength="500" placeholder="Add Google login"><?= e($intended) ?></textarea></label><button class="button button-primary" type="submit">Review change set</button></form><?php endif; ?>
<?php if ($result !== null): ?><?php if (isset($result['error'])): ?><div class="inline-error"><?= e($result['error']) ?></div><?php else: ?><article class="change-result"><h2><?= e($result['summary']) ?></h2><?php if ($result['scope_drift']['assessed']): ?><div class="risk-summary"><b><?= e($result['scope_drift']['label']) ?></b><span>Expected: <?= e(implode(', ', $result['scope_drift']['expected_areas'])) ?></span><span>Also changed: <?= e($result['scope_drift']['also_changed'] === [] ? 'none detected' : implode(', ', $result['scope_drift']['also_changed'])) ?></span><small><?= e($result['scope_drift']['note']) ?></small></div><?php endif; ?><?php if ($result['risky'] !== []): ?><div class="risk-summary"><b>Extra review recommended</b><?php foreach ($result['risky'] as $item): ?><span><?= e($item['risk'] . ': ' . $item['path']) ?></span><?php endforeach; ?></div><?php endif; ?><section class="delta-summary"><div><strong><?= count($result['symbol_delta']['added']) ?></strong><span>symbols added</span></div><div><strong><?= count($result['semantic_delta']['routes']['added']) + count($result['semantic_delta']['routes']['removed']) ?></strong><span>route changes</span></div><div><strong><?= count($result['semantic_delta']['schema']['added']) + count($result['semantic_delta']['schema']['removed']) ?></strong><span>schema changes</span></div><div><strong><?= count($result['semantic_delta']['dependencies']['added']) + count($result['semantic_delta']['dependencies']['removed']) ?></strong><span>dependency changes</span></div><div><strong><?= count($result['semantic_delta']['security']['added']) + count($result['semantic_delta']['security']['removed']) ?></strong><span>security changes</span></div></section>
<?php foreach (['routes' => 'Routes', 'schema' => 'Schema', 'dependencies' => 'Dependencies', 'security' => 'Security boundaries', 'environment' => 'Environment keys', 'architecture' => 'Architecture'] as $kind => $label): ?><?php $delta = $result['semantic_delta'][$kind]; if ($delta['added'] === [] && $delta['removed'] === []) continue; ?><section class="symbol-delta"><h3><?= e($label) ?></h3><div><?php foreach ($delta['added'] as $item): ?><p><span class="delta-kind added">Added</span><strong><?= e($item['route'] ?? $item['table'] ?? $item['name'] ?? $item['boundary'] ?? $item['area'] ?? $item['type']) ?></strong><small><?= e($item['path']) ?>:<?= (int) $item['line'] ?></small></p><?php endforeach; ?><?php foreach ($delta['removed'] as $item): ?><p><span class="delta-kind removed">Removed</span><strong><?= e($item['route'] ?? $item['table'] ?? $item['name'] ?? $item['boundary'] ?? $item['area'] ?? $item['type']) ?></strong><small><?= e($item['path']) ?>:<?= (int) $item['line'] ?></small></p><?php endforeach; ?></div></section><?php endforeach; ?>
<?php foreach ($result['groups'] as $group => $changes): ?><section class="change-group"><h3><?= e($group) ?></h3><div class="change-list"><?php foreach ($changes as $change): ?><p><span class="change-status"><?= e($change['status']) ?></span><span><?= e($change['path']) ?></span><?php if ($change['risk'] !== null): ?><b><?= e($change['risk']) ?></b><?php endif; ?></p><?php endforeach; ?></div></section><?php endforeach; ?><?php if ($result['stat'] !== []): ?><details><summary>Git diff statistics</summary><pre><?= e(implode("\n", $result['stat'])) ?></pre></details><?php endif; ?></article><?php endif; ?><?php endif; ?></section>
<section class="compare-card"><div class="section-row"><div><h2>AI Change Guard</h2><p>Capture normalized evidence before an AI edit, rescan after the edit, then capture After. Source and secret values are not stored.</p></div></div><?php if ($guardNotice): ?><div class="inline-success"><?= e($guardNotice) ?></div><?php endif; ?><form method="post" class="compare-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Snapshot label<input name="label" maxlength="180" placeholder="Google login pass"></label><label>Intended change<textarea name="intended_change" maxlength="500" placeholder="Add Google login"></textarea></label><div><button class="button button-secondary" name="action" value="capture_before" type="submit">Capture Before AI</button> <button class="button button-primary" name="action" value="capture_after" type="submit">Capture After AI</button></div></form><?php if ($guardComparison !== null): ?><article class="change-result"><h3><?= e($guardComparison['what_changed']) ?></h3><p>Potentially breakable evidence areas: <?= e($guardComparison['may_break'] === [] ? 'none detected' : implode(', ', $guardComparison['may_break'])) ?></p><h4>Tests to run</h4><ul><?php foreach ($guardComparison['tests'] as $test): ?><li><?= e($test) ?></li><?php endforeach; ?></ul><small><?= e($guardComparison['limitations']) ?></small></article><?php else: ?><div class="empty-inline">Capture a Before snapshot and a later After snapshot to create a guard comparison.</div><?php endif; ?></section></section>
<?php require __DIR__ . '/../views/footer.php'; ?>

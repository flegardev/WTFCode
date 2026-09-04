<?php

declare(strict_types=1);

use WTFCode\Application\ChangeImpactService;

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();

$projectId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$project = $projectId === false || $projectId === null ? null : Project::findForUser((int) $projectId, Auth::id());
if ($project === null) {
    http_response_code(404);
    exit('Project not found.');
}

$commits = GitDiffService::commits($project);
$result = null;
$impact = null;
$reviewPrompt = null;
$from = '';
$to = '';
$intended = '';
$guardNotice = null;
$guardError = null;
if (is_post()) {
    verify_csrf();
    $action = post_string('action');
    $intended = substr(post_string('intended_change'), 0, 500);
    if (in_array($action, ['capture_before', 'capture_after'], true)) {
        $phase = $action === 'capture_before' ? 'before' : 'after';
        $beforeSnapshotId = filter_var($_POST['before_snapshot_id'] ?? null, FILTER_VALIDATE_INT);
        try {
            ChangeGuardService::capture(
                (int) $project['id'],
                Auth::id(),
                $phase,
                post_string('label'),
                $intended,
                $beforeSnapshotId === false ? null : $beforeSnapshotId,
            );
            $guardNotice = $phase === 'before'
                ? 'Before snapshot captured. Complete a new analysis before capturing the after state.'
                : 'After snapshot captured and linked to its before state.';
        } catch (InvalidArgumentException $exception) {
            $guardError = $exception->getMessage();
        }
    } else {
        $from = post_string('from');
        $to = post_string('to');
        $result = GitDiffService::compare($project, $from, $to, $intended);
        if (!isset($result['error'])) {
            $impact = (new ChangeImpactService())->forDiff((int) $project['id'], $result);
            $reviewPrompt = PromptSafetyService::buildForReview((int) $project['id'], $intended, $result, $impact);
        }
    }
}
$pendingGuard = ChangeGuardService::pendingBefore((int) $project['id'], Auth::id());
$guardComparison = ChangeGuardService::latestComparison((int) $project['id'], Auth::id());
$pageTitle = 'Review changes';
$activePage = 'dashboard';
$activeProjectSection = 'review';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell v2-shell change-workbench">
    <div class="breadcrumb"><a href="<?= e(url('project.php?id=' . (int) $project['id'])) ?>"><?= e($project['name']) ?></a><span>/</span><span>Review Git diff</span></div>
    <div class="project-heading compact">
        <div><p class="landing-kicker">Change intelligence</p><h1>What will this diff break?</h1><p>Compare two observed commits, then follow changed files into symbols, routes, data, services, and tests.</p></div>
        <a class="button button-secondary" href="<?= e(url('change.php?id=' . (int) $project['id'])) ?>">Plan a target</a>
    </div>
    <?php require __DIR__ . '/../views/project-nav.php'; ?>

    <section class="compare-card diff-picker">
        <?php if ($commits === []): ?>
            <div class="empty-state-v2"><strong>Commit history is unavailable.</strong><p>The bounded repository copy may not include the desired history. Rescan or reconnect the repository, then try again.</p></div>
        <?php else: ?>
            <form method="post" class="compare-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="compare">
                <label><span>Earlier commit</span><select name="from" required><option value="">Choose a commit</option><?php foreach ($commits as $commit): ?><option value="<?= e($commit['sha']) ?>" <?= $from === $commit['sha'] ? 'selected' : '' ?>><?= e(substr($commit['sha'], 0, 8) . ' - ' . $commit['message']) ?></option><?php endforeach; ?></select></label>
                <label><span>Later commit</span><select name="to" required><option value="">Choose a commit</option><?php foreach ($commits as $commit): ?><option value="<?= e($commit['sha']) ?>" <?= $to === $commit['sha'] ? 'selected' : '' ?>><?= e(substr($commit['sha'], 0, 8) . ' - ' . $commit['message']) ?></option><?php endforeach; ?></select></label>
                <label><span>Intended change (optional)</span><textarea name="intended_change" maxlength="500" placeholder="Add Google login"><?= e($intended) ?></textarea></label>
                <button class="button button-primary" type="submit">Analyze impact</button>
            </form>
        <?php endif; ?>
    </section>

    <?php if ($result !== null && isset($result['error'])): ?><div class="inline-error" role="alert"><?= e($result['error']) ?></div><?php endif; ?>
    <?php if ($result !== null && $impact !== null): ?>
        <section class="change-risk-hero <?= e($impact['risk']['level']) ?>" aria-labelledby="diff-risk-title">
            <div><span>Change risk</span><strong id="diff-risk-title"><?= e(strtoupper($impact['risk']['level'])) ?></strong></div>
            <ul><?php foreach ($impact['risk']['reasons'] as $reason): ?><li><?= e($reason) ?></li><?php endforeach; ?></ul>
        </section>

        <section class="change-impact-metrics" aria-label="Git diff impact summary">
            <div><strong><?= count($result['changes']) ?></strong><span>files changed</span></div>
            <div><strong><?= count($impact['symbols']) + count($impact['direct']) + count($impact['transitive']) ?></strong><span>symbols affected</span></div>
            <div><strong><?= count($impact['routes']) ?></strong><span>routes affected</span></div>
            <div><strong><?= count($impact['tables']) + count($impact['services']) ?></strong><span>data and service boundaries</span></div>
        </section>

        <?php if (($impact['graph_context']['state'] ?? 'unknown') !== 'exact'): ?>
            <div class="context-warning" role="note"><strong>Graph context needs care.</strong><span><?= e($impact['graph_context']['message'] ?? 'Stored graph evidence may not match the selected commit.') ?></span></div>
        <?php endif; ?>

        <section class="change-result-grid">
            <article class="change-evidence-section">
                <div class="section-row"><div><h2>Critical impact</h2><p>Highest-signal dependent paths from changed symbols.</p></div></div>
                <?php if ($impact['chains'] === []): ?><div class="empty-state-v2"><strong>No transitive chain was resolved.</strong><p>The exact file and semantic diff remains available below.</p></div>
                <?php else: ?><div class="impact-chain-list"><?php foreach ($impact['chains'] as $chain): ?><ol><?php foreach ($chain as $node): ?><li><strong><?= e($node['name']) ?></strong><span><?= e(str_replace('_', ' ', $node['type'])) ?></span></li><?php endforeach; ?></ol><?php endforeach; ?></div><?php endif; ?>
            </article>
            <aside class="change-evidence-section">
                <div class="section-row"><div><h2>Likely tests</h2><p>Path matches only. Test coverage is not inferred.</p></div></div>
                <?php if ($impact['likely_tests'] === []): ?><div class="missing-test"><strong>No nearby test found</strong><p>Add a regression test for the affected behavior.</p></div>
                <?php else: ?><div class="likely-test-list"><?php foreach ($impact['likely_tests'] as $test): ?><a href="<?= e(url('file.php?project=' . (int) $project['id'] . '&id=' . (int) $test['id'])) ?>"><strong><?= e($test['path']) ?></strong><span><?= $test['matched_terms'] === [] ? 'Test-like path' : 'Matched ' . e(implode(', ', $test['matched_terms'])) ?></span></a><?php endforeach; ?></div><?php endif; ?>
            </aside>
        </section>

        <section class="change-evidence-section recommendations-section">
            <div class="section-row"><div><h2>Recommended before merge</h2><p>Checks derived from the observed change boundaries.</p></div></div>
            <ol class="recommendation-list"><?php foreach ($impact['recommendations'] as $recommendation): ?><li><?= e($recommendation) ?></li><?php endforeach; ?></ol>
        </section>

        <?php if ($reviewPrompt !== null): ?>
            <section class="change-evidence-section prompt-section">
                <div class="section-row"><div><h2>Implementation prompt</h2><p>Grounded in this commit range and its current stored blast radius.</p></div><button class="button button-secondary" type="button" data-copy-target="review-prompt">Copy prompt</button></div>
                <textarea id="review-prompt" class="prompt-output" readonly rows="20"><?= e($reviewPrompt['prompt']) ?></textarea>
                <p class="copy-status" data-copy-status aria-live="polite"></p>
            </section>
        <?php endif; ?>

        <details class="change-raw-evidence">
            <summary>Review exact Git and semantic evidence</summary>
            <article class="change-result">
                <h2><?= e($result['summary']) ?></h2>
                <?php if ($result['scope_drift']['assessed']): ?><div class="risk-summary"><b><?= e($result['scope_drift']['label']) ?></b><span>Expected: <?= e(implode(', ', $result['scope_drift']['expected_areas'])) ?></span><span>Also changed: <?= e($result['scope_drift']['also_changed'] === [] ? 'none detected' : implode(', ', $result['scope_drift']['also_changed'])) ?></span><small><?= e($result['scope_drift']['note']) ?></small></div><?php endif; ?>
                <section class="delta-summary">
                    <div><strong><?= count($result['symbol_delta']['added']) + count($result['symbol_delta']['removed']) + count($result['symbol_delta']['changed']) ?></strong><span>symbol changes</span></div>
                    <div><strong><?= count($result['semantic_delta']['routes']['added']) + count($result['semantic_delta']['routes']['removed']) ?></strong><span>route changes</span></div>
                    <div><strong><?= count($result['semantic_delta']['schema']['added']) + count($result['semantic_delta']['schema']['removed']) ?></strong><span>schema changes</span></div>
                    <div><strong><?= count($result['semantic_delta']['dependencies']['added']) + count($result['semantic_delta']['dependencies']['removed']) ?></strong><span>dependency changes</span></div>
                    <div><strong><?= count($result['semantic_delta']['security']['added']) + count($result['semantic_delta']['security']['removed']) ?></strong><span>security changes</span></div>
                </section>
                <?php foreach (['routes' => 'Routes', 'schema' => 'Schema', 'dependencies' => 'Dependencies', 'security' => 'Security boundaries', 'environment' => 'Environment keys', 'architecture' => 'Architecture'] as $kind => $label): ?><?php $delta = $result['semantic_delta'][$kind]; if ($delta['added'] === [] && $delta['removed'] === []) continue; ?><section class="symbol-delta"><h3><?= e($label) ?></h3><div><?php foreach ($delta['added'] as $item): ?><p><span class="delta-kind added">Added</span><strong><?= e($item['route'] ?? $item['table'] ?? $item['name'] ?? $item['boundary'] ?? $item['area'] ?? $item['type']) ?></strong><small><?= e($item['path']) ?>:<?= (int) $item['line'] ?></small></p><?php endforeach; ?><?php foreach ($delta['removed'] as $item): ?><p><span class="delta-kind removed">Removed</span><strong><?= e($item['route'] ?? $item['table'] ?? $item['name'] ?? $item['boundary'] ?? $item['area'] ?? $item['type']) ?></strong><small><?= e($item['path']) ?>:<?= (int) $item['line'] ?></small></p><?php endforeach; ?></div></section><?php endforeach; ?>
                <?php foreach ($result['groups'] as $group => $changes): ?><section class="change-group"><h3><?= e($group) ?></h3><div class="change-list"><?php foreach ($changes as $change): ?><p><span class="change-status"><?= e($change['status']) ?></span><span><?php if (is_string($change['old_path'] ?? null)): ?><?= e($change['old_path']) ?> → <?php endif; ?><?= e($change['path']) ?></span><?php if ($change['risk'] !== null): ?><b><?= e($change['risk']) ?></b><?php endif; ?></p><?php endforeach; ?></div></section><?php endforeach; ?>
                <?php if ($result['stat'] !== []): ?><details><summary>Git diff statistics</summary><pre><?= e(implode("\n", $result['stat'])) ?></pre></details><?php endif; ?>
            </article>
        </details>
    <?php endif; ?>

    <section class="compare-card guard-card">
        <div class="section-row"><div><h2>AI Change Guard</h2><p>Capture normalized evidence before an edit, complete a new scan, then capture the result. Every after snapshot is linked to its exact before snapshot. Source and secret values are not stored.</p></div></div>
        <?php if ($guardNotice): ?><div class="inline-success" role="status"><?= e($guardNotice) ?></div><?php endif; ?>
        <?php if ($guardError): ?><div class="inline-error" role="alert"><?= e($guardError) ?></div><?php endif; ?>
        <?php if ($pendingGuard !== null): ?>
            <div class="context-warning" role="status">
                <strong>Comparison pending</strong>
                <span>Before scan #<?= (int) $pendingGuard['scan_run_id'] ?><?= $pendingGuard['label'] !== '' ? ' · ' . e($pendingGuard['label']) : '' ?>. <?= $pendingGuard['can_capture_after'] ? 'A later scan is ready for the after capture.' : 'Complete a new analysis to unlock the after capture.' ?></span>
            </div>
        <?php endif; ?>
        <form method="post" class="compare-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <?php if ($pendingGuard !== null): ?><input type="hidden" name="before_snapshot_id" value="<?= (int) $pendingGuard['id'] ?>"><?php endif; ?>
            <label><span>Snapshot label</span><input name="label" maxlength="180" placeholder="Google login pass"></label>
            <label><span>Intended change</span><textarea name="intended_change" maxlength="500" placeholder="Add Google login"></textarea></label>
            <div><button class="button button-secondary" name="action" value="capture_before" type="submit" <?= $pendingGuard !== null ? 'disabled' : '' ?>>Capture before</button> <button class="button button-primary" name="action" value="capture_after" type="submit" <?= $pendingGuard === null || !$pendingGuard['can_capture_after'] ? 'disabled' : '' ?>>Capture after</button></div>
        </form>
        <?php if ($guardComparison !== null): ?><article class="change-result"><h3><?= e($guardComparison['what_changed']) ?></h3><p>Potentially breakable evidence areas: <?= e($guardComparison['may_break'] === [] ? 'none detected' : implode(', ', $guardComparison['may_break'])) ?></p><h4>Tests to run</h4><ul><?php foreach ($guardComparison['tests'] as $test): ?><li><?= e($test) ?></li><?php endforeach; ?></ul><small><?= e($guardComparison['limitations']) ?></small></article>
        <?php else: ?><div class="empty-inline">Capture a before snapshot and a later after snapshot to compare normalized evidence.</div><?php endif; ?>
    </section>
</section>
<script src="<?= e(url('assets/js/change-workbench.js')) ?>" defer></script>
<?php require __DIR__ . '/../views/footer.php'; ?>

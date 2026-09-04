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

$targetTypes = ['feature', 'symbol', 'file', 'route', 'table'];
$targetType = isset($_GET['type']) && is_string($_GET['type']) ? $_GET['type'] : 'feature';
$target = isset($_GET['target']) && is_string($_GET['target']) ? trim(substr($_GET['target'], 0, 160)) : '';
$request = '';
if (is_post()) {
    verify_csrf();
    $targetType = post_string('type');
    $target = substr(post_string('target'), 0, 160);
    $request = substr(post_string('request'), 0, 1000);
}
if (!in_array($targetType, $targetTypes, true)) $targetType = 'feature';

$impact = $target === '' ? null : (new ChangeImpactService())->forTarget((int) $project['id'], $target, $targetType);
$trace = $impact['trace'] ?? null;
$promptRequest = $request !== '' ? $request : ($target === '' ? '' : 'Change ' . $targetType . ' "' . $target . '" safely.');
$safePrompt = $promptRequest === '' ? null : PromptSafetyService::build((int) $project['id'], $promptRequest);

$pageTitle = 'Change ' . $project['name'];
$activePage = 'dashboard';
$activeProjectSection = 'change';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell v2-shell change-workbench">
    <div class="breadcrumb"><a href="<?= e(url('project.php?id=' . (int) $project['id'])) ?>"><?= e($project['name']) ?></a><span>/</span><span>Change</span></div>
    <div class="project-heading compact">
        <div><p class="landing-kicker">Change intelligence</p><h1>What will this change break?</h1><p>Select a concrete target. WTFCode follows stored routes, symbols, data boundaries, and dependents before you edit.</p></div>
        <a class="button button-secondary" href="<?= e(url('compare.php?id=' . (int) $project['id'])) ?>">Review Git diff</a>
    </div>
    <?php require __DIR__ . '/../views/project-nav.php'; ?>

    <form method="post" class="trace-search change-form change-target-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label><span>Target type</span><select name="type"><?php foreach ($targetTypes as $type): ?><option value="<?= e($type) ?>" <?= $targetType === $type ? 'selected' : '' ?>><?= e(ucfirst($type)) ?></option><?php endforeach; ?></select></label>
        <label><span>Target</span><input name="target" value="<?= e($target) ?>" placeholder="AuthService, POST /login, users" required></label>
        <label><span>Requested outcome</span><textarea name="request" maxlength="1000" placeholder="Describe the behavior you want to change."><?= e($request) ?></textarea></label>
        <button class="button button-primary" type="submit">Analyze impact</button>
    </form>

    <?php if ($impact !== null): ?>
        <section class="change-risk-hero <?= e($impact['risk']['level']) ?>" aria-labelledby="change-risk-title">
            <div><span>Change risk</span><strong id="change-risk-title"><?= e(strtoupper($impact['risk']['level'])) ?></strong></div>
            <ul><?php foreach ($impact['risk']['reasons'] as $reason): ?><li><?= e($reason) ?></li><?php endforeach; ?></ul>
        </section>

        <section class="change-impact-metrics" aria-label="Change impact summary">
            <div><strong><?= count($impact['files']) ?></strong><span>files in radius</span></div>
            <div><strong><?= count($impact['symbols']) + count($impact['direct']) + count($impact['transitive']) ?></strong><span>symbols affected</span></div>
            <div><strong><?= count($impact['routes']) ?></strong><span>routes affected</span></div>
            <div><strong><?= count($impact['tables']) + count($impact['services']) ?></strong><span>data and service boundaries</span></div>
        </section>

        <section class="change-result-grid">
            <article class="change-evidence-section">
                <div class="section-row"><div><h2>Critical impact</h2><p>Strongest dependency paths found from the selected target.</p></div></div>
                <?php if ($impact['chains'] === []): ?>
                    <div class="empty-state-v2"><strong>No connected impact chain was resolved.</strong><p>Review the matched files directly. Dynamic calls can remain outside the static graph.</p></div>
                <?php else: ?>
                    <div class="impact-chain-list">
                        <?php foreach ($impact['chains'] as $chain): ?><ol><?php foreach ($chain as $node): ?><li><strong><?= e($node['name']) ?></strong><span><?= e(str_replace('_', ' ', $node['type'])) ?></span></li><?php endforeach; ?></ol><?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>

            <aside class="change-evidence-section">
                <div class="section-row"><div><h2>Likely tests</h2><p>Matched by real test paths and affected names. This is not a coverage claim.</p></div></div>
                <?php if ($impact['likely_tests'] === []): ?>
                    <div class="missing-test"><strong>No nearby test found</strong><p>Add a focused regression test before relying on this change.</p></div>
                <?php else: ?>
                    <div class="likely-test-list"><?php foreach ($impact['likely_tests'] as $test): ?><a href="<?= e(url('file.php?project=' . (int) $project['id'] . '&id=' . (int) $test['id'])) ?>"><strong><?= e($test['path']) ?></strong><span><?= $test['matched_terms'] === [] ? 'Test-like path' : 'Matched ' . e(implode(', ', $test['matched_terms'])) ?></span></a><?php endforeach; ?></div>
                <?php endif; ?>
            </aside>
        </section>

        <section class="change-evidence-section recommendations-section">
            <div class="section-row"><div><h2>Recommended before merge</h2><p>Checks derived from the boundaries visible in this result.</p></div></div>
            <ol class="recommendation-list"><?php foreach ($impact['recommendations'] as $recommendation): ?><li><?= e($recommendation) ?></li><?php endforeach; ?></ol>
        </section>

        <?php if ($safePrompt !== null): ?>
            <section class="change-evidence-section prompt-section">
                <div class="section-row"><div><h2>Implementation prompt</h2><p>Generated from the selected target and bounded repository evidence.</p></div><button class="button button-secondary" type="button" data-copy-target="change-prompt">Copy prompt</button></div>
                <textarea id="change-prompt" class="prompt-output" readonly rows="18"><?= e($safePrompt['prompt']) ?></textarea>
                <p class="copy-status" data-copy-status aria-live="polite"></p>
            </section>
        <?php endif; ?>

        <details class="change-raw-evidence">
            <summary>Review trace evidence and limitations</summary>
            <p><?= e($impact['limitations']) ?></p>
            <?php if ($trace !== null): ?><dl><div><dt>Trace confidence</dt><dd><?= e($trace['confidence']) ?></dd></div><div><dt>Direct matches</dt><dd><?= count($trace['symbols']) + count($trace['entry_points']) ?></dd></div><div><dt>Evidence hops</dt><dd><?= count($trace['hops']) ?></dd></div></dl><?php endif; ?>
            <a class="button button-quiet" href="<?= e(url('feature.php?id=' . (int) $project['id'] . '&q=' . urlencode($target))) ?>">Open full trace</a>
        </details>
    <?php endif; ?>
</section>
<script src="<?= e(url('assets/js/change-workbench.js')) ?>" defer></script>
<?php require __DIR__ . '/../views/footer.php'; ?>

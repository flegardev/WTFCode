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
        <div><p class="landing-kicker">Before your next edit</p><h1>What could this change affect?</h1><p>Know what a change could affect before you make it.</p></div>
        <a class="button button-secondary" href="<?= e(url('compare.php?id=' . (int) $project['id'])) ?>">Review Git diff</a>
    </div>
    <?php require __DIR__ . '/../views/project-nav.php'; ?>

    <details class="change-request"<?= $impact === null ? ' open' : '' ?>><summary><?= $impact === null ? 'Describe your change' : 'Edit planned change · ' . e(basename($target)) ?></summary>
    <form method="post" class="change-form change-target-form" data-change-form>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label for="planned-change">What are you planning to change?</label><textarea id="planned-change" name="request" rows="2" maxlength="1000" placeholder="For example, I want to change the login system."><?= e($request) ?></textarea>
        <div class="intent-examples" aria-label="Example changes"><?php foreach (['I want to change the login system.', 'I want to modify the database.', 'I want to add payments.'] as $example): ?><button type="button" data-change-intent="<?= e($example) ?>"><?= e($example) ?></button><?php endforeach; ?></div>
        <p class="subtle">Next, choose where to look. Your description guides the review prompt; the concrete target below determines which code is analyzed. New features may have no existing match.</p>
        <div class="change-target-row"><label><span>Look for a</span><select name="type"><?php foreach ($targetTypes as $type): ?><option value="<?= e($type) ?>" <?= $targetType === $type ? 'selected' : '' ?>><?= e(ucfirst($type)) ?></option><?php endforeach; ?></select></label><label><span>Name or path in this repository</span><input name="target" value="<?= e($target) ?>" maxlength="160" placeholder="For example: authentication or src/auth.ts" aria-describedby="target-help" required></label><button class="button button-primary" type="submit">Analyze impact →</button></div>
        <p class="subtle" id="target-help">Use a feature, exact symbol, route, table or file path from your scan. <a href="<?= e(url('files.php?id=' . (int) $project['id'])) ?>">Find a source file</a>.</p><p data-change-status role="status" hidden>Following stored source connections…</p>
    </form></details>
    <?php if ($impact !== null): ?>
        <section class="impact-intro" id="impact-result"><p class="eyebrow">Analysis result</p><h2>Here's what we found.</h2><p class="subtle">Target: <code><?= e($target) ?></code><?= $request !== '' ? ' · ' . e($request) : '' ?></p><?php if ($impact['resolved_target_count'] === 0): ?><p class="summary-lead">No concrete target matched “<?= e($target) ?>”.</p><p>Try an exact file path or symbol from the scan. No match does not mean the proposed change is safe.</p><?php else: ?><p class="summary-lead"><?= count($impact['files']) ?> file<?= count($impact['files']) === 1 ? '' : 's' ?> are included in the selected target and its detected dependency neighborhood.</p><p>These are places to inspect, not a prediction that they will break.</p><?php endif; ?><div class="certainty-key"><span>Detected: source relationships</span><span>Inferred: possible consequences</span><span>Unknown: runtime effects</span></div></section>
        <?php if ($impact['files'] !== []): ?><section class="change-evidence-section"><h2>Connected code to inspect</h2><p>Includes the selected files and static relationships. Each label describes extraction confidence, not proof of a working feature.</p><div class="impact-file-list"><?php foreach ($impact['files'] as $fileIndex => $affectedFile): ?><?php if ($fileIndex === 6): ?></div><details><summary>More connected files (<?= count($impact['files']) - 6 ?>)</summary><div class="impact-file-list"><?php endif; ?><a href="<?= e(url(($matchedFile = Project::fileByPath((int) $project['id'], (string) $affectedFile['path'])) !== null ? 'file.php?project=' . (int) $project['id'] . '&id=' . (int) $matchedFile['id'] : 'files.php?id=' . (int) $project['id'] . '&q=' . urlencode((string) $affectedFile['path']))) ?>"><strong><?= e($affectedFile['path']) ?></strong><span><?= e(isset($affectedFile['confidence']) ? ucfirst($affectedFile['confidence']) . ' confidence · inspect source' : 'Selected target · inspect source') ?> <span aria-hidden="true">→</span></span></a><?php endforeach; ?></div><?php if (count($impact['files']) > 6): ?></details><?php endif; ?></section><?php endif; ?>

        <details class="advanced-section"><summary>Connection details · symbols, routes and boundaries</summary><section class="change-result-grid">
            <article class="change-evidence-section">
                <div class="section-row"><div><h2>Detected connections</h2><p>Each pair is a stored direct relationship. Confidence can vary; these are not an execution sequence.</p></div></div>
                <?php if ($impact['chains'] === []): ?>
                    <div class="empty-state-v2"><strong>No connected impact chain was resolved.</strong><p>Review the matched files directly. Dynamic calls can remain outside the static graph.</p></div>
                <?php else: ?>
                    <div class="impact-chain-list">
                        <?php foreach ($impact['chains'] as $chain): ?><ol><?php foreach ($chain as $node): ?><li><a href="<?= e(url('symbol.php?project=' . (int) $project['id'] . '&id=' . (int) $node['id'])) ?>"><strong><?= e($node['name']) ?></strong></a><span><?= e(str_replace('_', ' ', $node['type'])) ?></span></li><?php endforeach; ?></ol><?php endforeach; ?>
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

        <dl class="overview-facts"><div><dt>Files in neighborhood</dt><dd><?= count($impact['files']) ?></dd></div><div><dt>Matched and reachable symbols</dt><dd><?= count(array_unique(array_column(array_merge($impact['symbols'], $impact['direct'], $impact['transitive']), 'id'))) ?></dd></div><div><dt>Related routes</dt><dd><?= count($impact['routes']) ?></dd></div><div><dt>Data / configuration / service boundaries</dt><dd><?= count($impact['tables']) + count($impact['services']) ?></dd></div></dl>
        <?php if ($impact['routes'] !== [] || $impact['tables'] !== [] || $impact['services'] !== []): ?><h3>Potentially affected areas</h3><ul class="boundary-list"><?php foreach ($impact['routes'] as $route): ?><li><?= e($route['label'] ?? $route['route_path'] ?? 'Related request handler') ?></li><?php endforeach; ?><?php foreach (array_merge($impact['tables'], $impact['services']) as $boundary): ?><li><?= e($boundary['name'] ?? 'Detected boundary') ?> <small><?= e(str_replace('_', ' ', $boundary['type'] ?? $boundary['symbol_type'] ?? 'boundary')) ?></small></li><?php endforeach; ?></ul><?php endif; ?>
        <p class="subtle">This view is bounded to 20 matched seed symbols, six dependency hops per seed and at most 150 displayed files. Wider runtime effects require manual review.</p>
        <section class="change-risk-hero"><div><span>Static review estimate</span><strong><?= e(ucfirst($impact['risk']['level'])) ?></strong></div><p>Heuristic review priority, not a prediction of breakage.</p><ul><?php foreach ($impact['risk']['reasons'] as $reason): ?><li><?= e($reason) ?></li><?php endforeach; ?></ul></section></details>
        <section class="change-evidence-section recommendations-section">
            <div class="section-row"><div><h2>Your review checklist</h2><p>Checks derived from the boundaries visible in this result.</p></div></div>
            <ol class="recommendation-list"><?php foreach ($impact['recommendations'] as $recommendation): ?><li><?= e($recommendation) ?></li><?php endforeach; ?></ol>
        </section>

        <?php if ($safePrompt !== null): ?>
            <details class="advanced-section"><summary>Implementation prompt · copy for your coding assistant</summary><section class="change-evidence-section prompt-section">
                <div class="section-row"><div><h2 id="change-prompt-title">Implementation prompt</h2><p>Generated from the selected target and bounded repository evidence.</p></div><button class="button button-secondary" type="button" data-copy-target="change-prompt">Copy prompt</button></div>
                <textarea id="change-prompt" aria-labelledby="change-prompt-title" class="prompt-output" readonly rows="18"><?= e($safePrompt['prompt']) ?></textarea>
                <p class="copy-status" data-copy-status aria-live="polite"></p>
            </section></details>
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

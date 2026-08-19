<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
$projectId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$project = $projectId === false || $projectId === null ? null : Project::findForUser((int) $projectId, Auth::id());
if ($project === null) { http_response_code(404); exit('Project not found.'); }
$nodeKey = isset($_GET['feature']) && is_string($_GET['feature']) ? trim($_GET['feature']) : '';
$node = $nodeKey === '' ? null : Project::architectureNode((int) $project['id'], $nodeKey);
$query = isset($_GET['q']) && is_string($_GET['q']) ? trim(substr($_GET['q'], 0, 160)) : '';
if ($node !== null && $query === '') $query = $node['node_key'];
$trace = $query === '' ? FeatureTracer::trace((int) $project['id'], '') : ExplanationService::traceFeatureV2((int) $project['id'], $query);
$evidencePaths = $node === null ? [] : (json_decode((string) $node['evidence_json'], true) ?: []);
$evidenceFiles = $node === null ? [] : Project::filesForPaths((int) $project['id'], $evidencePaths);
$pageTitle = $query === '' ? 'Trace a feature' : 'Trace ' . $query;
$activePage = 'dashboard';
$activeProjectSection = 'trace';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell v2-shell trace-page">
    <div class="breadcrumb"><a href="<?= e(url('project.php?id=' . (int) $project['id'])) ?>"><?= e($project['name']) ?></a><span>/</span><span>Feature trace</span></div>
    <div class="project-heading compact"><div><p class="landing-kicker">Cross-layer flow</p><h1>Trace a feature end to end.</h1><p>Start with a route, symbol, table, or file name. Every hop reports confidence and the source line that supports it.</p></div></div>
    <?php require __DIR__ . '/../views/project-nav.php'; ?>
    <form class="trace-search" method="get"><input type="hidden" name="id" value="<?= (int) $project['id'] ?>"><label><span>Feature, route, or symbol</span><input name="q" value="<?= e($query) ?>" placeholder="authentication, /login, checkout, UserController"></label><button class="button button-primary" type="submit">Trace evidence</button></form>
    <?php if ($node !== null && $evidenceFiles !== []): ?><section class="node-evidence-strip"><strong><?= e($node['label']) ?> evidence</strong><?php foreach ($evidenceFiles as $file): ?><a href="<?= e(url('file.php?project=' . (int) $project['id'] . '&id=' . (int) $file['id'])) ?>"><?= e($file['path']) ?></a><?php endforeach; ?></section><?php endif; ?>
    <?php if ($query === ''): ?><section class="panel empty-state-v2"><strong>Choose a real feature name.</strong><p>Good starting points are a route path, controller, table, component, authentication, database, or API.</p></section><?php else: ?>
        <section class="trace-status"><div><span>Trace confidence</span><strong class="confidence <?= e($trace['confidence']) ?>"><?= e($trace['confidence']) ?></strong></div><div><span>Entry points</span><strong><?= count($trace['entry_points']) ?></strong></div><div><span>Symbols</span><strong><?= count($trace['symbols']) ?></strong></div><div><span>Evidence hops</span><strong><?= count($trace['hops']) ?></strong></div><div><span>Files</span><strong><?= count($trace['files']) ?></strong></div></section>
        <?php if ($trace['entry_points'] !== []): ?><section class="panel trace-entry-panel"><div class="section-row"><div><h2>Entry points</h2><p>Routes that directly matched the trace.</p></div></div><div class="trace-entry-grid"><?php foreach ($trace['entry_points'] as $entry): ?><?php $entryParts = explode(' ', $entry['label'], 2); ?><article><span class="http-method method-<?= e(strtolower($entryParts[0])) ?>"><?= e($entryParts[0]) ?></span><strong><?= e($entryParts[1] ?? '/') ?></strong><small><?= e($entry['framework']) ?> in <?= e($entry['path']) ?>:<?= (int) $entry['line'] ?></small><?php if ($entry['handler_id']): ?><a href="<?= e(url('symbol.php?project=' . (int) $project['id'] . '&id=' . (int) $entry['handler_id'])) ?>">Open <?= e($entry['handler_name'] ?: 'handler') ?></a><?php endif; ?></article><?php endforeach; ?></div></section><?php endif; ?>
        <section class="trace-layout"><article class="panel hop-panel"><div class="section-row"><div><h2>Evidence path</h2><p>Internal targets are linked. External names remain explicitly unresolved.</p></div><span class="count-mark"><?= count($trace['hops']) ?></span></div><?php if ($trace['hops'] === []): ?><div class="empty-state-v2"><strong>No connected hop was proven.</strong><p>Matching files and symbols are still listed below. Dynamic wiring may need manual inspection.</p></div><?php else: ?><ol class="hop-list"><?php foreach ($trace['hops'] as $hop): ?><li><span class="hop-depth"><?= (int) $hop['depth'] ?></span><div><p><strong><?= e($hop['source_name'] ?: 'File scope') ?></strong><span><?= e(str_replace('_', ' ', $hop['relationship'])) ?></span><?php if ($hop['target_id']): ?><a href="<?= e(url('symbol.php?project=' . (int) $project['id'] . '&id=' . (int) $hop['target_id'])) ?>"><?= e($hop['target_name']) ?></a><?php else: ?><b><?= e($hop['target_name']) ?></b><?php endif; ?></p><small><?= e($hop['evidence_path']) ?><?= $hop['line'] ? ':' . (int) $hop['line'] : '' ?>, <?= e($hop['confidence']) ?> confidence</small><?php if ($hop['excerpt']): ?><code><?= e($hop['excerpt']) ?></code><?php endif; ?></div></li><?php endforeach; ?></ol><?php endif; ?></article>
        <aside class="trace-side"><section class="panel"><h2>Files in this trace</h2><div class="boundary-list"><?php foreach ($trace['files'] as $file): ?><a href="<?= e(url('file.php?project=' . (int) $project['id'] . '&id=' . (int) $file['id'])) ?>"><strong><?= e($file['path']) ?></strong><span><?= e($file['reason']) ?>, <?= e($file['confidence']) ?> confidence</span></a><?php endforeach; ?></div></section><section class="panel"><h2>Boundaries reached</h2><dl class="trace-boundaries"><div><dt>Data</dt><dd><?= count($trace['tables']) ?></dd></div><div><dt>Services</dt><dd><?= count($trace['services']) ?></dd></div><div><dt>Environment</dt><dd><?= count($trace['environment']) ?></dd></div><div><dt>Unresolved</dt><dd><?= count($trace['unknowns']) ?></dd></div></dl><?php if ($trace['unknowns'] !== []): ?><details><summary>Review unresolved targets</summary><ul><?php foreach (array_slice($trace['unknowns'], 0, 20) as $unknown): ?><li><code><?= e($unknown['name']) ?></code><span><?= e($unknown['confidence']) ?> confidence</span></li><?php endforeach; ?></ul></details><?php endif; ?></section></aside></section>
        <section class="trace-warnings"><strong>Limits to verify</strong><?php foreach ($trace['warnings'] as $warning): ?><p><?= e($warning) ?></p><?php endforeach; ?></section>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../views/footer.php'; ?>

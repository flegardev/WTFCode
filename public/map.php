<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
$projectId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$project = $projectId === false || $projectId === null ? null : Project::findForUser((int) $projectId, Auth::id());
if ($project === null) { http_response_code(404); exit('Project not found.'); }
$graph = SymbolRepository::graph((int) $project['id'], 100);
$types = array_values(array_unique(array_column($graph['nodes'], 'symbol_type')));
sort($types);
$pageTitle = 'Symbol graph for ' . $project['name'];
$activePage = 'dashboard';
$activeProjectSection = 'graph';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell v2-shell graph-page">
    <div class="breadcrumb"><a href="<?= e(url('project.php?id=' . (int) $project['id'])) ?>"><?= e($project['name']) ?></a><span>/</span><span>Symbol graph</span></div>
    <div class="project-heading compact"><div><p class="landing-kicker">Interactive architecture</p><h1>Follow the evidence.</h1><p>Select a node to isolate connected symbols. Search and confidence filters run locally against this server-rendered graph.</p></div><a class="button button-secondary" href="<?= e(url('symbols.php?id=' . (int) $project['id'])) ?>">Browse symbols</a></div>
    <?php require __DIR__ . '/../views/project-nav.php'; ?>
    <?php if ($graph['nodes'] === []): ?><section class="panel empty-state-v2"><strong>No V2 graph is available.</strong><p>Rescan this repository after applying migration 002. V1 file and architecture views remain available.</p></section><?php else: ?>
    <section class="graph-workbench" data-project="<?= (int) $project['id'] ?>">
        <aside class="graph-controls"><label><span>Search nodes</span><input id="graph-search" placeholder="Controller, login, User"></label><label><span>Node type</span><select id="graph-type"><option value="">Every type</option><?php foreach ($types as $type): ?><option value="<?= e($type) ?>"><?= e(str_replace('_', ' ', $type)) ?></option><?php endforeach; ?></select></label><label><span>Minimum confidence</span><select id="graph-confidence"><option value="low">Low and above</option><option value="medium" selected>Medium and above</option><option value="high">High only</option></select></label><button class="button button-quiet" id="graph-reset" type="button">Reset view</button><div id="graph-detail" class="graph-detail" aria-live="polite"><strong>Select a symbol</strong><p>Connected nodes and edges stay bright. Every edge keeps its evidence file and line.</p></div></aside>
        <div class="graph-viewport" id="graph-viewport" tabindex="0" aria-label="Interactive symbol relationship graph"><svg id="graph-edges" aria-hidden="true"></svg><div id="graph-nodes" class="graph-nodes"><?php foreach ($graph['nodes'] as $node): ?><button type="button" class="graph-node" data-id="<?= (int) $node['id'] ?>" data-type="<?= e($node['symbol_type']) ?>" data-confidence="<?= e($node['confidence']) ?>" data-name="<?= e(strtolower($node['name'])) ?>" data-path="<?= e(strtolower($node['path'])) ?>"><span><?= e(str_replace('_', ' ', $node['symbol_type'])) ?></span><strong><?= e($node['name']) ?></strong><small><?= e($node['path']) ?>:<?= (int) $node['start_line'] ?></small></button><?php endforeach; ?></div></div>
    </section>
    <noscript><section class="panel empty-state-v2"><strong>Interactive focus needs JavaScript.</strong><p>The complete symbol index and every detail page remain server rendered and usable without it.</p></section></noscript>
    <script id="symbol-graph-data" type="application/json"><?= json_encode($graph, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?></script>
    <script src="<?= e(url('assets/js/symbol-map.js')) ?>" defer></script>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../views/footer.php'; ?>

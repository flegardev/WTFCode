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
$relationships = array_values(array_unique(array_column($graph['edges'], 'relationship_type')));
$subsystems = array_values(array_unique(array_column($graph['nodes'], 'subsystem')));
$features = array_values(array_unique(array_column($graph['nodes'], 'feature')));
$frameworks = array_values(array_filter(array_unique(array_column($graph['nodes'], 'framework')), static fn (string $value): bool => $value !== 'Unspecified'));
sort($relationships); sort($subsystems); sort($features); sort($frameworks);
$pageTitle = 'Symbol graph for ' . $project['name'];
$activePage = 'dashboard';
$activeProjectSection = 'graph';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell v2-shell graph-page">
    <div class="breadcrumb"><a href="<?= e(url('project.php?id=' . (int) $project['id'])) ?>"><?= e($project['name']) ?></a><span>/</span><span>Symbol graph</span></div>
    <div class="project-heading compact"><div><p class="landing-kicker">Interactive architecture</p><h1>Follow the evidence.</h1><p>Choose a direction and depth, inspect a neighborhood, or trace the strongest static path between two symbols.</p></div><a class="button button-secondary" href="<?= e(url('symbols.php?id=' . (int) $project['id'])) ?>">Browse symbols</a></div>
    <?php require __DIR__ . '/../views/project-nav.php'; ?>
    <?php if ($graph['nodes'] === []): ?><section class="panel empty-state-v2"><strong>No symbol graph is available.</strong><p>Complete a new analysis. File and architecture views remain available.</p></section><?php else: ?>
    <section class="graph-workbench" data-project="<?= (int) $project['id'] ?>">
        <aside class="graph-controls">
            <div class="graph-control-primary">
                <label><span>Search nodes</span><input id="graph-search" placeholder="Controller, login, User"></label>
                <label><span>Level of detail</span><select id="graph-level"><option value="architecture" selected>Architecture</option><option value="subsystem">Subsystem</option><option value="feature">Feature</option><option value="file">File</option><option value="symbol">Symbol</option></select></label>
                <div class="graph-traversal-controls">
                    <label><span>Neighbor direction</span><select id="graph-direction"><option value="both">Dependencies and dependents</option><option value="outgoing">Dependencies</option><option value="incoming">Dependents</option></select></label>
                    <label><span>Traversal depth</span><select id="graph-depth"><option value="1" selected>1 hop</option><option value="2">2 hops</option><option value="3">3 hops</option><option value="all">All connected</option></select></label>
                </div>
            </div>
            <details class="graph-filter-details">
                <summary>Filter graph</summary>
                <div>
                    <label><span>Node type</span><select id="graph-type"><option value="">Every type</option><?php foreach ($types as $type): ?><option value="<?= e($type) ?>"><?= e(str_replace('_', ' ', $type)) ?></option><?php endforeach; ?></select></label>
                    <label><span>Relationship</span><select id="graph-relationship"><option value="">Every relationship</option><?php foreach ($relationships as $relationship): ?><option value="<?= e($relationship) ?>"><?= e(str_replace('_', ' ', $relationship)) ?></option><?php endforeach; ?></select></label>
                    <label><span>Subsystem</span><select id="graph-subsystem"><option value="">Every subsystem</option><?php foreach ($subsystems as $subsystem): ?><option value="<?= e($subsystem) ?>"><?= e($subsystem) ?></option><?php endforeach; ?></select></label>
                    <label><span>Feature</span><select id="graph-feature"><option value="">Every feature</option><?php foreach ($features as $feature): ?><option value="<?= e($feature) ?>"><?= e($feature) ?></option><?php endforeach; ?></select></label>
                    <label><span>Framework</span><select id="graph-framework"><option value="">Every framework</option><?php foreach ($frameworks as $framework): ?><option value="<?= e($framework) ?>"><?= e($framework) ?></option><?php endforeach; ?></select></label>
                    <label><span>Risk</span><select id="graph-risk"><option value="">Every risk</option><option value="high">High</option><option value="medium">Medium</option><option value="low">Low</option></select></label>
                    <label><span>Minimum confidence</span><select id="graph-confidence"><option value="low">Low and above</option><option value="medium" selected>Medium and above</option><option value="high">High only</option></select></label>
                </div>
            </details>
            <div class="graph-actions"><button class="button button-quiet" id="graph-fit" type="button">Fit graph</button><button class="button button-quiet" id="graph-reset" type="button">Reset</button></div>
            <div id="graph-detail" class="graph-detail" aria-live="polite"><strong>Select a node</strong><p>Its chosen neighborhood stays bright. Use the text view below for a keyboard-readable index.</p></div>
        </aside>
        <div class="graph-stage">
            <div class="graph-legend-bar">
                <div class="graph-legend" aria-label="Graph color legend"><span><i class="request"></i>Request</span><span><i class="service"></i>Service</span><span><i class="data"></i>Data</span><span><i class="external"></i>External</span><span><i class="risk"></i>High risk</span></div>
                <strong id="graph-result-count" aria-live="polite"><?= count($graph['nodes']) ?> nodes, <?= count($graph['edges']) ?> relationships</strong>
            </div>
            <div class="graph-path-tools"><label><span>From</span><select id="graph-path-from"><option value="">Choose a symbol</option><?php foreach ($graph['nodes'] as $node): ?><option value="<?= (int) $node['id'] ?>"><?= e($node['name'] . ' · ' . $node['path']) ?></option><?php endforeach; ?></select></label><label><span>To</span><select id="graph-path-to"><option value="">Choose a symbol</option><?php foreach ($graph['nodes'] as $node): ?><option value="<?= (int) $node['id'] ?>"><?= e($node['name'] . ' · ' . $node['path']) ?></option><?php endforeach; ?></select></label><button class="button button-primary" id="graph-trace" type="button">Trace strongest path</button><span id="graph-path-result" aria-live="polite"></span></div>
            <p class="graph-instructions" id="graph-instructions">Select a node to focus its neighborhood. Double-click a group to drill into the next level.</p>
            <div class="graph-viewport" id="graph-viewport" tabindex="0" aria-label="Interactive code relationship graph" aria-describedby="graph-instructions"></div>
        </div>
    </section>
    <details class="graph-text-fallback"><summary>Browse graph nodes as text</summary><p>This index stays available to keyboard and screen-reader users. The full symbol directory contains every stored symbol.</p><ul><?php foreach (array_slice($graph['nodes'], 0, 80) as $node): ?><li><a href="<?= e(url('symbol.php?project=' . (int) $project['id'] . '&id=' . (int) $node['id'])) ?>"><strong><?= e($node['name']) ?></strong><span><?= e(str_replace('_', ' ', $node['symbol_type'])) ?> in <?= e($node['path']) ?>:<?= (int) $node['start_line'] ?></span></a></li><?php endforeach; ?></ul><a href="<?= e(url('symbols.php?id=' . (int) $project['id'])) ?>">Open the full symbol directory</a></details>
    <noscript><section class="panel empty-state-v2"><strong>The visual graph needs JavaScript.</strong><p>Use the text index above or open the complete symbol directory.</p></section></noscript>
    <script nonce="<?= e(csp_nonce()) ?>" id="symbol-graph-data" type="application/json"><?= json_encode($graph, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?></script>
    <script src="<?= e(url('assets/lib/cytoscape-3.34.1.min.js')) ?>" defer></script>
    <script src="<?= e(url('assets/js/symbol-map.js')) ?>" defer></script>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../views/footer.php'; ?>

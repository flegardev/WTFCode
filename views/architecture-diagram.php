<?php
// Stored integration evidence only. Architecture edges remain explicitly inferred.
$overviewGraph = [
    'nodes' => array_map(static fn(array $node): array => ['key' => $node['node_key'], 'type' => $node['node_type'], 'label' => $node['label'], 'explanation' => $node['plain_explanation']], $architecture['nodes']),
    'edges' => array_map(static fn(array $edge): array => ['from' => $edge['from_key'], 'to' => $edge['to_key'], 'relationship' => $edge['relationship_label']], $architecture['edges']),
];
?>
        <article class="overview-section architecture-overview" id="architecture">
            <header><div><p class="eyebrow">02 / How it fits together</p><h2>How does it work?</h2><p>Select a detected part to see its explanation. Dashed connections are inferred.</p></div><a href="<?= e(url(($activeProjectSection === 'graph' ? 'understand.php' : 'map.php') . '?id=' . (int) $project['id'])) ?>"><?= $activeProjectSection === 'graph' ? 'Read architecture brief →' : 'Explore architecture →' ?></a></header>
            <?php if ($architecture['nodes'] === []): ?><div class="empty-inline">The scan did not find enough strong integration evidence to draw an architecture map yet.</div>
            <?php else: ?>
                <button class="button button-quiet mobile-diagram-toggle" type="button" data-diagram-toggle aria-expanded="false" aria-controls="diagram-visuals">Open interactive diagram</button>
                <div class="diagram-visuals" id="diagram-visuals"><div class="diagram-toolbar" aria-label="Diagram controls"><span>Detected systems · inferred connections</span><div><button type="button" data-diagram-zoom="in" aria-label="Zoom in">+</button><button type="button" data-diagram-zoom="out" aria-label="Zoom out">−</button><button type="button" data-diagram-fit>Fit</button></div></div>
                <div class="overview-architecture-graph" id="overview-architecture-graph" data-node-base="<?= e(url('feature.php?id=' . (int) $project['id'] . '&feature=')) ?>" tabindex="0" role="img" aria-label="Detected architecture graph"></div>
                </div>
                <div class="overview-architecture-detail" id="overview-architecture-detail" aria-live="polite"><strong>Select a system</strong><span>See its scan explanation, then open the supporting evidence.</span></div>
                <div class="system-buttons" aria-label="Select a detected system"><?php foreach ($architecture['nodes'] as $node): ?><button type="button" data-system-key="<?= e($node['node_key']) ?>"><?= e($node['label']) ?></button><?php endforeach; ?></div>
                <?php if ($architecture['edges'] !== []): ?><details class="architecture-relations"><summary>Inferred relationship summary</summary><div aria-label="Inferred architecture connections"><?php foreach (array_slice($architecture['edges'], 0, 6) as $edge): ?><p><strong><?= e($edge['from_label']) ?></strong><span><?= e($edge['relationship_label']) ?></span><strong><?= e($edge['to_label']) ?></strong></p><?php endforeach; ?></div></details><?php endif; ?>
                <details class="overview-architecture-text"><summary>Browse detected systems as text</summary><ul><?php foreach ($architecture['nodes'] as $node): ?><li><a href="<?= e(url('feature.php?id=' . (int) $project['id'] . '&feature=' . urlencode((string) $node['node_key']))) ?>"><strong><?= e((string) $node['label']) ?></strong><span><?= e((string) $node['plain_explanation']) ?></span></a></li><?php endforeach; ?></ul></details>
                <script nonce="<?= e(csp_nonce()) ?>" id="overview-architecture-data" type="application/json"><?= json_encode($overviewGraph, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?></script>
            <?php endif; ?>
        </article>

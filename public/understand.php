<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
$projectId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$project = $projectId === false || $projectId === null ? null : Project::findForUser((int) $projectId, Auth::id());
if ($project === null) { http_response_code(404); exit('Project not found.'); }
$architecture = Project::architecture((int) $project['id']);
$features = SymbolRepository::featureCatalog((int) $project['id']);
$tables = SymbolRepository::tables((int) $project['id']);
$services = array_values(array_filter(SymbolRepository::services((int) $project['id']), static fn (array $item): bool => ($item['symbol_type'] ?? '') === 'external_service'));
$stack = json_decode((string) $project['stack_json'], true) ?: [];
$deployment = array_values(array_filter($architecture['nodes'], static fn (array $node): bool => in_array($node['node_key'], ['deployment', 'docker'], true)));
$pageTitle = 'Understand ' . $project['name'];
$activePage = 'dashboard';
$activeProjectSection = 'understand';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell v2-shell"><div class="breadcrumb"><a href="<?= e(url('project.php?id=' . (int) $project['id'])) ?>"><?= e($project['name']) ?></a><span>/</span><span>Understand</span></div><div class="project-heading compact"><div><p class="landing-kicker">Understand</p><h1>Build a truthful mental model.</h1><p><?= e($project['overview'] ?? 'The scan has not produced an overview yet.') ?></p></div><a class="button button-secondary" href="<?= e(url('ask.php?id=' . (int) $project['id'])) ?>">Ask the evidence</a></div><?php require __DIR__ . '/../views/project-nav.php'; ?>
<?php if ($stack !== []): ?><div class="stack-line"><span>Actually detected</span><?php foreach ($stack as $item): ?><b><?= e($item) ?></b><?php endforeach; ?></div><?php endif; ?>
<section class="content-grid"><article class="panel"><div class="section-row"><div><h2>Architecture</h2><p>Evidence-backed systems; inferred links stay labeled.</p></div><a href="<?= e(url('map.php?id=' . (int) $project['id'])) ?>">Open graph</a></div><div class="architecture-nodes"><?php foreach ($architecture['nodes'] as $node): ?><?php $architectureNode = $node; $architectureHref = url('feature.php?id=' . (int) $project['id'] . '&feature=' . urlencode($node['node_key'])); require __DIR__ . '/../views/architecture-node.php'; ?><?php endforeach; ?></div></article><aside class="panel"><h2>Main features</h2><?php if ($features === []): ?><p>No multi-signal feature cluster was proven.</p><?php else: ?><div class="boundary-list"><?php foreach ($features as $feature): ?><a href="<?= e(url('feature.php?id=' . (int) $project['id'] . '&q=' . urlencode($feature['label']))) ?>"><strong><?= e($feature['label']) ?></strong><span><?= (int) $feature['symbols'] ?> supporting symbols</span></a><?php endforeach; ?></div><?php endif; ?></aside></section>
<section class="data-columns"><article class="panel"><h2>Data</h2><p><?= count($tables) ?> detected tables, models, or schemas.</p><div class="boundary-list"><?php foreach (array_slice($tables, 0, 12) as $table): ?><a href="<?= e(url('symbol.php?project=' . (int) $project['id'] . '&id=' . (int) $table['id'])) ?>"><strong><?= e($table['name']) ?></strong><span><?= e($table['symbol_type']) ?> · <?= e($table['path']) ?></span></a><?php endforeach; ?></div></article><article class="panel"><h2>Services and deployment</h2><p>Runtime/config evidence only; documentation does not prove use.</p><div class="boundary-list"><?php foreach (array_slice($services, 0, 12) as $service): ?><a href="<?= e(url('symbol.php?project=' . (int) $project['id'] . '&id=' . (int) $service['id'])) ?>"><strong><?= e($service['name']) ?></strong><span><?= e(str_replace('_', ' ', $service['symbol_type'])) ?></span></a><?php endforeach; ?><?php foreach ($deployment as $node): ?><a href="<?= e(url('feature.php?id=' . (int) $project['id'] . '&feature=' . urlencode($node['node_key']))) ?>"><strong><?= e($node['label']) ?></strong><span>deployment evidence</span></a><?php endforeach; ?></div></article></section></section>
<?php require __DIR__ . '/../views/footer.php'; ?>

<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
$projectId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$project = $projectId === false || $projectId === null ? null : Project::findForUser((int) $projectId, Auth::id());
if ($project === null) { http_response_code(404); exit('Project not found.'); }
$tables = SymbolRepository::tables((int) $project['id']);
$services = SymbolRepository::services((int) $project['id']);
$externalServices = array_values(array_filter($services, static fn (array $item): bool => $item['symbol_type'] === 'external_service'));
$environment = array_values(array_filter($services, static fn (array $item): bool => $item['symbol_type'] === 'environment_variable'));
$pageTitle = 'Data and services in ' . $project['name'];
$activePage = 'dashboard';
$activeProjectSection = 'data';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell v2-shell">
    <div class="breadcrumb"><a href="<?= e(url('project.php?id=' . (int) $project['id'])) ?>"><?= e($project['name']) ?></a><span>/</span><span>Data and services</span></div>
    <div class="project-heading compact"><div><p class="landing-kicker">Boundaries</p><h1>What the code depends on.</h1><p>Detected tables, models, schemas, environment keys, and external hosts. References are evidence, not proof that production uses every path.</p></div></div>
    <?php require __DIR__ . '/../views/project-nav.php'; ?>
    <section class="data-summary"><div><strong><?= count($tables) ?></strong><span>data structures</span></div><div><strong><?= count($externalServices) ?></strong><span>service references</span></div><div><strong><?= count($environment) ?></strong><span>environment reads</span></div></section>
    <section class="panel data-index"><div class="section-row"><div><h2>Tables, models, and schemas</h2><p>Definitions and query references can produce more than one row for the same logical table.</p></div></div><?php if ($tables === []): ?><div class="empty-state-v2"><strong>No data structure detected.</strong><p>Check SQL migrations, ORM models, or queries manually if data is configured dynamically.</p></div><?php else: ?><div class="data-card-grid"><?php foreach ($tables as $table): ?><a href="<?= e(url('symbol.php?project=' . (int) $project['id'] . '&id=' . (int) $table['id'])) ?>"><span><?= e($table['symbol_type']) ?></span><strong><?= e($table['name']) ?></strong><small><?= e($table['path']) ?>:<?= (int) $table['start_line'] ?></small><dl><div><dt>Columns</dt><dd><?= (int) $table['column_count'] ?></dd></div><div><dt>Edges</dt><dd><?= (int) $table['relationship_count'] ?></dd></div></dl></a><?php endforeach; ?></div><?php endif; ?></section>
    <section class="data-columns"><article class="panel"><div class="section-row"><div><h2>External services</h2><p>Hosts and URLs visible in readable source.</p></div><span class="count-mark"><?= count($externalServices) ?></span></div><?php if ($externalServices === []): ?><div class="empty-state-v2"><strong>No service host detected.</strong><p>SDK configuration or runtime secrets can hide the final endpoint.</p></div><?php else: ?><div class="boundary-list"><?php foreach ($externalServices as $service): ?><a href="<?= e(url('symbol.php?project=' . (int) $project['id'] . '&id=' . (int) $service['id'])) ?>"><strong><?= e($service['name']) ?></strong><span><?= e($service['path']) ?>, <?= (int) $service['reference_count'] ?> incoming</span></a><?php endforeach; ?></div><?php endif; ?></article><article class="panel"><div class="section-row"><div><h2>Environment keys</h2><p>Names only. Secret values are never stored.</p></div><span class="count-mark"><?= count($environment) ?></span></div><?php if ($environment === []): ?><div class="empty-state-v2"><strong>No environment read detected.</strong><p>Configuration can still arrive through another mechanism.</p></div><?php else: ?><div class="env-key-cloud"><?php foreach ($environment as $item): ?><a href="<?= e(url('symbol.php?project=' . (int) $project['id'] . '&id=' . (int) $item['id'])) ?>"><?= e($item['name']) ?><small><?= e($item['path']) ?>:<?= (int) $item['start_line'] ?></small></a><?php endforeach; ?></div><?php endif; ?></article></section>
</section>
<?php require __DIR__ . '/../views/footer.php'; ?>

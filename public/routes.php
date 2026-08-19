<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
$projectId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$project = $projectId === false || $projectId === null ? null : Project::findForUser((int) $projectId, Auth::id());
if ($project === null) { http_response_code(404); exit('Project not found.'); }
$search = isset($_GET['q']) && is_string($_GET['q']) ? trim(substr($_GET['q'], 0, 160)) : '';
$routes = SymbolRepository::routes((int) $project['id'], $search);
$frameworks = [];
foreach ($routes as $route) $frameworks[$route['framework']][] = $route;
$pageTitle = 'Routes in ' . $project['name'];
$activePage = 'dashboard';
$activeProjectSection = 'routes';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell v2-shell">
    <div class="breadcrumb"><a href="<?= e(url('project.php?id=' . (int) $project['id'])) ?>"><?= e($project['name']) ?></a><span>/</span><span>Routes</span></div>
    <div class="project-heading compact"><div><p class="landing-kicker">Request index</p><h1>Where requests enter.</h1><p>Framework routes, methods, handlers, middleware clues, and the exact file line that declared each entry point.</p></div><a class="button button-secondary" href="<?= e(url('feature.php?id=' . (int) $project['id'])) ?>">Trace a feature</a></div>
    <?php require __DIR__ . '/../views/project-nav.php'; ?>
    <form class="route-search" method="get"><input type="hidden" name="id" value="<?= (int) $project['id'] ?>"><label><span>Filter routes</span><input name="q" value="<?= e($search) ?>" placeholder="GET, /login, users, routes/api.php"></label><button class="button button-primary" type="submit">Filter routes</button></form>
    <?php if ($routes === []): ?><section class="panel empty-state-v2"><strong>No routes match this view.</strong><p>The analyzer only records routes supported by framework or file-system evidence. Dynamic registration can remain unknown.</p></section><?php else: ?>
        <?php foreach ($frameworks as $framework => $items): ?><section class="panel route-group"><div class="section-row"><div><h2><?= e($framework) ?></h2><p><?= count($items) ?> route<?= count($items) === 1 ? '' : 's' ?> from static evidence.</p></div></div><div class="route-list"><?php foreach ($items as $route): ?><article><span class="http-method method-<?= e(strtolower($route['http_method'])) ?>"><?= e($route['http_method']) ?></span><div><strong><?= e($route['route_path']) ?></strong><small><?= e($route['path']) ?>:<?= (int) $route['evidence_line'] ?></small></div><div class="route-handler"><?php if ($route['handler_symbol_id']): ?><a href="<?= e(url('symbol.php?project=' . (int) $project['id'] . '&id=' . (int) $route['handler_symbol_id'])) ?>"><?= e($route['handler_name']) ?></a><?php else: ?><span>Handler unresolved</span><?php endif; ?><small><?= e($route['confidence']) ?> confidence</small></div><a class="row-action" href="<?= e(url('feature.php?id=' . (int) $project['id'] . '&q=' . urlencode($route['route_path']))) ?>">Trace</a></article><?php endforeach; ?></div></section><?php endforeach; ?>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../views/footer.php'; ?>

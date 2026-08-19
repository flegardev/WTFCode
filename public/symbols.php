<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
$projectId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$project = $projectId === false || $projectId === null ? null : Project::findForUser((int) $projectId, Auth::id());
if ($project === null) { http_response_code(404); exit('Project not found.'); }
$search = isset($_GET['q']) && is_string($_GET['q']) ? trim(substr($_GET['q'], 0, 160)) : '';
$type = isset($_GET['type']) && is_string($_GET['type']) ? trim(substr($_GET['type'], 0, 50)) : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$types = SymbolRepository::types((int) $project['id']);
$allowedTypes = array_column($types, 'symbol_type');
if ($type !== '' && !in_array($type, $allowedTypes, true)) $type = '';
$symbolPage = SymbolRepository::symbolsPage((int) $project['id'], $search, $type, $page);
$pageTitle = 'Symbols in ' . $project['name'];
$activePage = 'dashboard';
$activeProjectSection = 'symbols';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell v2-shell">
    <div class="breadcrumb"><a href="<?= e(url('dashboard.php')) ?>">Projects</a><span>/</span><a href="<?= e(url('project.php?id=' . (int) $project['id'])) ?>"><?= e($project['name']) ?></a><span>/</span><span>Symbols</span></div>
    <div class="project-heading compact"><div><p class="landing-kicker">Source outline</p><h1>Symbols, not just files.</h1><p>Search classes, functions, methods, components, tables, environment keys, and detected service boundaries.</p></div><a class="button button-secondary" href="<?= e(url('map.php?id=' . (int) $project['id'])) ?>">Open graph</a></div>
    <?php require __DIR__ . '/../views/project-nav.php'; ?>
    <form class="symbol-filter" method="get">
        <input type="hidden" name="id" value="<?= (int) $project['id'] ?>">
        <label><span>Find a symbol</span><input name="q" value="<?= e($search) ?>" placeholder="User, authenticate, GET, accounts"></label>
        <label><span>Symbol type</span><select name="type"><option value="">Every type</option><?php foreach ($types as $item): ?><option value="<?= e($item['symbol_type']) ?>" <?= $type === $item['symbol_type'] ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $item['symbol_type'])) ?> (<?= (int) $item['total'] ?>)</option><?php endforeach; ?></select></label>
        <button class="button button-primary" type="submit">Filter symbols</button>
    </form>
    <section class="panel symbol-index-panel">
        <div class="section-row"><div><h2><?= (int) $symbolPage['total'] ?> matching symbols</h2><p>Confidence describes extraction certainty, not proof of runtime behavior.</p></div><span class="analysis-badge">analysis <?= e(SymbolRepository::latestScan((int) $project['id'])['analysis_version'] ?? 'v1') ?></span></div>
        <?php if ($symbolPage['symbols'] === []): ?><div class="empty-state-v2"><strong>No normalized symbols match.</strong><p>Try a shorter name, another type, or rescan the repository after applying the V2 migration.</p></div><?php else: ?>
            <div class="symbol-index-list"><?php foreach ($symbolPage['symbols'] as $symbol): ?><a href="<?= e(url('symbol.php?project=' . (int) $project['id'] . '&id=' . (int) $symbol['id'])) ?>"><span class="symbol-type-mark"><?= e(str_replace('_', ' ', $symbol['symbol_type'])) ?></span><span class="symbol-main"><strong><?= e($symbol['name']) ?></strong><small><?= e($symbol['path']) ?>:<?= (int) $symbol['start_line'] ?></small></span><span class="symbol-edge-count"><?= (int) $symbol['incoming_count'] ?> in<br><?= (int) $symbol['outgoing_count'] ?> out</span><span class="confidence <?= e($symbol['confidence']) ?>"><?= e($symbol['confidence']) ?></span></a><?php endforeach; ?></div>
        <?php endif; ?>
    </section>
    <?php if ($symbolPage['pages'] > 1): ?><nav class="pagination" aria-label="Symbol pages"><?php if ($symbolPage['page'] > 1): ?><a class="button button-quiet" href="<?= e(url('symbols.php?id=' . (int) $project['id'] . '&q=' . urlencode($search) . '&type=' . urlencode($type) . '&page=' . ($symbolPage['page'] - 1))) ?>">Previous</a><?php endif; ?><span>Page <?= (int) $symbolPage['page'] ?> of <?= (int) $symbolPage['pages'] ?></span><?php if ($symbolPage['page'] < $symbolPage['pages']): ?><a class="button button-quiet" href="<?= e(url('symbols.php?id=' . (int) $project['id'] . '&q=' . urlencode($search) . '&type=' . urlencode($type) . '&page=' . ($symbolPage['page'] + 1))) ?>">Next</a><?php endif; ?></nav><?php endif; ?>
</section>
<?php require __DIR__ . '/../views/footer.php'; ?>

<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
$projectId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$project = $projectId === false || $projectId === null ? null : Project::findForUser((int) $projectId, Auth::id());
if ($project === null) { http_response_code(404); exit('Project not found.'); }
$search = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$requestedPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1;
$filePage = Project::filesPage((int) $project['id'], $search, (int) $requestedPage, 50);
$pageTitle = 'Files in ' . $project['name'];
$activePage = 'dashboard';
$activeProjectSection = 'files';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell"><div class="breadcrumb"><a href="<?= e(url('dashboard.php')) ?>">Projects</a><span>/</span><a href="<?= e(url('project.php?id=' . (int) $project['id'])) ?>"><?= e($project['name']) ?></a><span>/</span><span>Files</span></div><div class="project-heading compact"><div><p class="landing-kicker">Repository files</p><h1>Browse the map.</h1><p>Search by path, detected role, or language. Results are paginated to keep large repositories responsive.</p></div></div><?php require __DIR__ . '/../views/project-nav.php'; ?><form class="file-search" method="get"><input type="hidden" name="id" value="<?= (int) $project['id'] ?>"><label class="sr-only" for="file-search">Search repository files</label><input id="file-search" name="q" value="<?= e($search) ?>" placeholder="Search files, routes, authentication, configuration"><button class="button button-primary" type="submit">Search</button></form><section class="panel files-panel"><div class="section-row"><h2><?= (int) $filePage['total'] ?> file<?= (int) $filePage['total'] === 1 ? '' : 's' ?></h2><a href="<?= e(url('project.php?id=' . (int) $project['id'])) ?>">Back to project</a></div><?php if ($filePage['files'] === []): ?><div class="empty-inline">No readable files match this search.</div><?php else: ?><div class="file-list"><?php foreach ($filePage['files'] as $file): ?><a href="<?= e(url('file.php?project=' . (int) $project['id'] . '&id=' . (int) $file['id'])) ?>"><div><span><?= e($file['role_name']) ?></span><strong><?= e($file['path']) ?></strong><small><?= e($file['language']) ?>, <?= (int) $file['line_count'] ?> lines</small></div><div class="file-impact"><b><?= (int) $file['dependent_count'] ?></b><span>direct dependent<?= (int) $file['dependent_count'] === 1 ? '' : 's' ?></span></div></a><?php endforeach; ?></div><?php endif; ?></section><?php if ($filePage['pages'] > 1): ?><nav class="pagination" aria-label="File pages"><?php if ($filePage['page'] > 1): ?><a class="button button-quiet" href="<?= e(url('files.php?id=' . (int) $project['id'] . '&q=' . urlencode($search) . '&page=' . ((int) $filePage['page'] - 1))) ?>">Previous</a><?php endif; ?><span>Page <?= (int) $filePage['page'] ?> of <?= (int) $filePage['pages'] ?></span><?php if ($filePage['page'] < $filePage['pages']): ?><a class="button button-quiet" href="<?= e(url('files.php?id=' . (int) $project['id'] . '&q=' . urlencode($search) . '&page=' . ((int) $filePage['page'] + 1))) ?>">Next</a><?php endif; ?></nav><?php endif; ?></section>
<?php require __DIR__ . '/../views/footer.php'; ?>

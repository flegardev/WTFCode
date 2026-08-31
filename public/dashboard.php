<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
$userId = Auth::id();
$stats = Project::dashboardStats($userId);
$projects = Project::allForUser($userId);
$explorationPercentage = ExplorationService::workspacePercentage($userId);
$pageTitle = 'Projects';
$activePage = 'dashboard';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell">
    <div class="app-heading">
        <div><p class="landing-kicker">Repository workspace</p><h1>Understand before you edit.</h1><p>Your analysis stays tied to the projects you import.</p></div>
        <a class="button button-primary" href="<?= e(url('project-create.php')) ?>">Import repository</a>
    </div>
    <section class="metric-grid">
        <article><span>Repositories</span><strong><?= (int) $stats['project_count'] ?></strong><p>In your workspace</p></article>
        <article><span>Files mapped</span><strong><?= (int) $stats['file_count'] ?></strong><p>Across completed scans</p></article>
        <article><span>Exploration</span><strong><?= (int) $explorationPercentage ?>%</strong><p>Lessons you marked explored</p></article>
        <article><span>Understand first</span><strong><?= (int) $stats['attention_count'] ?></strong><p>Attention items found</p></article>
    </section>
    <section class="project-area">
        <div class="section-row"><div><h2>Your projects</h2><p>Open a repository to explore its evidence map.</p></div></div>
        <?php if ($projects === []): ?>
            <div class="empty-panel">
                <h2>Import your first GitHub repository.</h2>
                <p>Use a public GitHub URL. WTFCode will scan it in temporary private storage and persist only derived evidence.</p>
                <a class="button button-primary" href="<?= e(url('project-create.php')) ?>">Import first repository</a>
            </div>
        <?php else: ?>
            <div class="project-grid">
                <?php foreach ($projects as $project): ?>
                    <?php $projectProgress = ExplorationService::progress($userId, (int) $project['id']); ?>
                    <a class="project-card" href="<?= e(url('project.php?id=' . (int) $project['id'])) ?>">
                        <div><span class="status <?= e($project['status']) ?>"><?= e($project['status']) ?></span><h3><?= e($project['name']) ?></h3><p><?= e(parse_url($project['repository_url'], PHP_URL_PATH) ?: $project['repository_url']) ?></p></div>
                        <dl><div><dt>Files</dt><dd><?= (int) $project['file_count'] ?></dd></div><div><dt>Explore</dt><dd><?= $projectProgress['percentage'] ?>%</dd></div><div><dt>Review</dt><dd><?= (int) $project['attention_count'] ?></dd></div></dl>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</section>
<?php require __DIR__ . '/../views/footer.php'; ?>

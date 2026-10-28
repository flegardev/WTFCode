<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
$userId = (int) Auth::id();
$projects = Project::allForUser($userId);
$usage = Entitlements::usage($userId);
$pageTitle = 'Your projects';
$activePage = 'dashboard';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell workspace-layout">
    <aside class="workspace-sidebar" aria-label="Workspace">
        <div><p class="sidebar-label">Your workspace</p><a class="sidebar-link is-active" href="<?= e(url('dashboard.php')) ?>" aria-current="page">Projects <span><?= count($projects) ?></span></a><a class="sidebar-link" href="<?= e(url('project-create.php')) ?>">Connect repository <span aria-hidden="true">+</span></a></div>
        <div class="usage-card"><span class="plan-badge">Free trial</span><h2>Room to explore.</h2><label for="scan-usage">Scans in the last 30 days</label><progress id="scan-usage" max="<?= $usage['limits']['scans'] ?>" value="<?= min($usage['scans'], $usage['limits']['scans']) ?>"></progress><p><?= $usage['scans'] ?> / <?= $usage['limits']['scans'] ?> scans · <?= $usage['projects'] ?> / <?= $usage['limits']['projects'] ?> projects</p><a href="<?= e(url('pricing.php')) ?>">View proposed plans <span aria-hidden="true">↗</span></a></div>
    </aside>
    <div class="workspace-content">
        <div class="app-heading"><div><p class="landing-kicker">A little clarity goes a long way</p><h1>Your projects</h1><p>Understand your codebase, one question at a time.</p></div><a class="button button-primary" href="<?= e(url('project-create.php')) ?>"><span aria-hidden="true">+</span> Add repository</a></div>
        <?php if ($projects === []): ?>
            <div class="empty-panel"><span class="empty-mark" aria-hidden="true">{ }</span><h2>Meet the app you've been building.</h2><p>Connect a public GitHub repository. We'll map what is there, show how the pieces connect, and help you plan your next change.</p><a class="button button-primary" href="<?= e(url('project-create.php')) ?>">Connect first repository <span aria-hidden="true">→</span></a><small>No repository code is executed. No payment details needed.</small></div>
        <?php else: ?>
            <div class="repository-list">
                <?php foreach ($projects as $item): ?>
                    <?php $stack = json_decode((string) ($item['stack_json'] ?? '[]'), true); ?>
                    <article class="repository-card">
                        <div class="repository-title"><span class="repo-mark" aria-hidden="true">{ }</span><div><h2><?= e($item['name']) ?></h2><p><?= e(trim((string) parse_url($item['repository_url'], PHP_URL_PATH), '/')) ?></p></div><span class="status <?= e($item['status']) ?>"><?= e($item['status']) ?></span></div>
                        <?php if (!empty($item['last_scan_at'])): ?><p class="repository-summary"><?= e($item['overview'] ?: 'Your repository analysis is ready to explore.') ?></p><?php else: ?><p class="repository-summary">Your first scan is <?= e($item['status']) ?>. Open the project to follow its progress.</p><?php endif; ?>
                        <div class="repository-bottom"><dl><div><dt>Files mapped</dt><dd><?= number_format((int) $item['file_count']) ?></dd></div><div><dt>Review leads</dt><dd><?= (int) $item['attention_count'] ?></dd></div><div><dt>Last analysis</dt><dd><?= empty($item['last_scan_at']) ? 'Not yet scanned' : e(date('M j, H:i', (int) strtotime($item['last_scan_at']))) ?></dd></div></dl><a class="button button-secondary" href="<?= e(url('project.php?id=' . (int) $item['id'])) ?>">View analysis <span aria-hidden="true">→</span></a></div>
                        <?php if (is_array($stack) && $stack !== []): ?><div class="repository-stack"><?php foreach (array_slice($stack, 0, 6) as $technology): ?><span><?= e($technology) ?></span><?php endforeach; ?></div><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
            <p class="workspace-note">Findings reflect the last scan. Review leads need your judgment; they are not confirmed vulnerabilities.</p>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/../views/footer.php'; ?>

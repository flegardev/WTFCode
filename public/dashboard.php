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
<section class="app-shell dashboard-page">
    <div class="workspace-content">
        <div class="app-heading"><div><p class="landing-kicker">Your workspace</p><h1>Your projects</h1><p>Understand your applications and keep track of what matters.</p></div><a class="button button-primary" href="<?= e(url('project-create.php')) ?>"><span aria-hidden="true">+</span> Add project</a></div>
        <?php if ($projects === []): ?>
            <div class="empty-panel"><span class="empty-mark"><?= ui_icon('folder-git-2') ?></span><h2>Your first project starts here.</h2><p>Connect a public GitHub repository. We'll map what is there, show how the pieces connect, and help you plan your next change.</p><a class="button button-primary" href="<?= e(url('project-create.php')) ?>">Analyze your first GitHub project <span aria-hidden="true">→</span></a><small>No repository code is executed. No payment details needed.</small><a class="example-link" href="<?= e(url('example.php')) ?>">Or explore a real public example →</a></div>
        <?php else: ?>
            <div class="repository-list">
                <?php foreach ($projects as $item): ?>
                    <?php $stack = json_decode((string) ($item['stack_json'] ?? '[]'), true); $themes = array_values(array_filter(Project::findings((int) $item['id'], 100), static fn(array $finding): bool => in_array($finding['severity'], ['risk', 'attention'], true))); $plainSummary = \WTFCode\Application\ProjectUnderstanding::summary(Project::architecture((int) $item['id'])['nodes'], SymbolRepository::routes((int) $item['id']), is_array($stack) ? $stack : []); ?>
                    <article class="repository-card">
                        <div class="repository-title"><span class="repo-mark"><?= ui_icon('folder-git-2') ?></span><div><h2><?= e($item['name']) ?></h2><p><?= e(trim((string) parse_url($item['repository_url'], PHP_URL_PATH), '/')) ?></p></div><span class="status <?= e($item['status']) ?>"><?= e($item['status']) ?></span></div>
                        <?php if (!empty($item['last_scan_at'])): ?><p class="repository-summary"><?= e($plainSummary) ?></p><?php else: ?><p class="repository-summary">Your first scan is <?= e($item['status']) ?>. Open the project to follow its progress.</p><?php endif; ?>
                        <?php if ($themes !== []): ?><div class="project-review-note"><span>Worth a closer look</span><p><?= e($themes[0]['title']) ?> <a href="<?= e(url('secure.php?id=' . (int) $item['id'])) ?>">Review evidence →</a></p><small>Static review lead, not a confirmed vulnerability.</small></div><?php endif; ?>
                        <div class="repository-bottom"><dl><div><dt>Files mapped</dt><dd><?= number_format((int) $item['file_count']) ?></dd></div><div><dt>Review themes</dt><dd><?= count($themes) ?></dd></div><div><dt>Last analysis</dt><dd><?= empty($item['last_scan_at']) ? 'Not yet scanned' : e(date('M j, H:i', (int) strtotime($item['last_scan_at']))) ?></dd></div></dl><a class="button button-secondary" href="<?= e(url('project.php?id=' . (int) $item['id'])) ?>">Explore project <span aria-hidden="true">→</span></a></div>
                        <?php if (is_array($stack) && $stack !== []): ?><div class="repository-stack"><?php foreach (array_slice($stack, 0, 6) as $technology): ?><span><?= e($technology) ?></span><?php endforeach; ?></div><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
            <p class="workspace-note">Findings reflect the last scan. Review leads need your judgment; they are not confirmed vulnerabilities.</p>
        <?php endif; ?>
        <p class="usage-inline">Public trial · <?= $usage['projects'] ?> / <?= $usage['limits']['projects'] ?> projects · <?= $usage['scans'] ?> / <?= $usage['limits']['scans'] ?> scans in the last 30 days. <a href="<?= e(url('settings.php')) ?>">Usage and preferences</a></p>
    </div>
</section>
<?php require __DIR__ . '/../views/footer.php'; ?>

<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
$projectId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$project = $projectId === false || $projectId === null ? null : Project::findForUser((int) $projectId, Auth::id());
if ($project === null) { http_response_code(404); exit('Project not found.'); }
$findings = Project::securityFindings((int) $project['id']);
$packages = Project::packageInventory((int) $project['id']);
$runs = Project::analyzerRuns((int) $project['id']);
$groups = [
    'Secrets' => ['possible_secret', 'possible_exposed_secret'],
    'Vulnerable dependencies' => ['dependency_vulnerability'],
    'Code findings' => ['code_security_finding', 'dynamic_code_execution'],
    'Auth boundaries' => ['authentication_boundary'],
    'Process execution' => ['process_execution', 'process_or_dynamic_execution'],
    'File operations' => ['file_operation', 'destructive_database_operation'],
];
$pageTitle = 'Secure ' . $project['name'];
$activePage = 'dashboard';
$activeProjectSection = 'secure';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell v2-shell security-console">
    <div class="breadcrumb"><a href="<?= e(url('dashboard.php')) ?>">Projects</a><span>/</span><a href="<?= e(url('project.php?id=' . (int) $project['id'])) ?>"><?= e($project['name']) ?></a><span>/</span><span>Secure</span></div>
    <div class="project-heading compact"><div><p class="landing-kicker">Security evidence</p><h1>Review boundaries, not a made-up score.</h1><p>Findings are grouped by impact and retain analyzer, rule, file, and advisory evidence. A finding is a review lead, not proof of exploitability.</p></div></div>
    <?php require __DIR__ . '/../views/project-nav.php'; ?>
    <section class="security-summary-grid">
        <article><span>Confirmed</span><strong><?= count(array_filter($findings, static fn (array $item): bool => ($item['evidence']['confidence'] ?? '') === 'confirmed')) ?></strong><p>Multiple engines or high-certainty evidence.</p></article>
        <article><span>Needs review</span><strong><?= count(array_filter($findings, static fn (array $item): bool => in_array($item['severity'], ['risk', 'attention'], true))) ?></strong><p>Prioritized without claiming exploitation.</p></article>
        <article><span>Packages inventoried</span><strong><?= count($packages) ?></strong><p>Declared, resolved, or detected by available SBOM evidence.</p></article>
    </section>
    <section class="panel analyzer-strip"><div class="section-row"><div><h2>Security analyzers</h2><p>Each provider succeeds, fails, or remains unavailable independently.</p></div></div><div class="analyzer-health-list"><?php foreach ($runs as $run): ?><span class="engine-state <?= e($run['status']) ?>"><b><?= e($run['engine_id']) ?></b> <?= e($run['status']) ?><?php if ($run['engine_version']): ?> · <?= e($run['engine_version']) ?><?php endif; ?></span><?php endforeach; ?><?php if ($runs === []): ?><p class="muted-copy">No persisted analyzer run is available yet.</p><?php endif; ?></div></section>
    <?php foreach ($groups as $label => $types): ?><?php $items = array_values(array_filter($findings, static fn (array $item): bool => in_array($item['finding_type'], $types, true))); ?>
        <section class="panel security-group"><div class="section-row"><div><h2><?= e($label) ?></h2><p><?= $items === [] ? 'No finding was reported by the analyzers that ran.' : count($items) . ' evidence-backed item' . (count($items) === 1 ? '' : 's') . ' to review.' ?></p></div><span class="count-mark"><?= count($items) ?></span></div>
            <?php if ($items !== []): ?><div class="security-finding-list"><?php foreach ($items as $item): ?><article class="finding <?= e($item['severity']) ?>"><div><span><?= e($item['severity']) ?></span><strong><?= e($item['title']) ?></strong></div><p><?= e($item['plain_explanation']) ?></p><small><?php if ($item['file_path']): ?><?= e($item['file_path']) ?><?php if ($item['evidence']['line'] ?? null): ?>:<?= (int) $item['evidence']['line'] ?><?php endif; ?> · <?php endif; ?><?= e($item['evidence']['engine'] ?? $item['evidence']['source'] ?? 'WTFCode') ?><?php if ($item['evidence']['rule_id'] ?? null): ?> · <?= e($item['evidence']['rule_id']) ?><?php endif; ?></small></article><?php endforeach; ?></div><?php endif; ?>
        </section>
    <?php endforeach; ?>
    <section class="panel security-group"><div class="section-row"><div><h2>Dependency inventory</h2><p>Classification distinguishes manifest declarations, lockfile resolutions, and package detection.</p></div><span class="count-mark"><?= count($packages) ?></span></div><?php if ($packages !== []): ?><div class="package-table"><?php foreach ($packages as $package): ?><div><span class="package-class"><?= e($package['classification']) ?></span><strong><?= e($package['package_name']) ?></strong><code><?= e($package['package_version'] ?: 'version unknown') ?></code><small><?= e($package['ecosystem']) ?></small></div><?php endforeach; ?></div><?php endif; ?></section>
</section>
<?php require __DIR__ . '/../views/footer.php'; ?>

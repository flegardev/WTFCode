<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
$projectId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$project = $projectId === false || $projectId === null ? null : Project::findForUser((int) $projectId, Auth::id());
if ($project === null) { http_response_code(404); exit('Project not found.'); }
$registry = (new AnalyzerRegistry())->status();
$doctor = (new ToolDoctor())->inspect(dirname(__DIR__));
$job = AnalysisJobStore::latest((int) $project['id']);
$pageTitle = 'Analyzers for ' . $project['name']; $activePage = 'dashboard'; $activeProjectSection = 'analyzers';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell v2-shell"><div class="breadcrumb"><a href="<?= e(url('project.php?id=' . (int) $project['id'])) ?>"><?= e($project['name']) ?></a><span>/</span><span>Analyzers</span></div><div class="project-heading compact"><div><p class="landing-kicker">Analyzer registry</p><h1>What can run, exactly?</h1><p>Unavailable optional engines remain visible and fail independently. Cache and incremental reuse are identified per job step.</p></div></div><?php require __DIR__ . '/../views/project-nav.php'; ?>
<section class="panel"><div class="section-row"><div><h2>Profiles</h2><p>The selected profile controls orchestration, not installation.</p></div></div><div class="data-card-grid"><?php foreach ([AnalysisProfile::QUICK => 'Native plus primary AST engines', AnalysisProfile::DEEP => 'All semantic and code analyzers', AnalysisProfile::SECURITY => 'Secrets, static security, vulnerabilities, optional SBOM', AnalysisProfile::MAXIMUM => 'Every installed provider, including Git-history secrets'] as $profile => $description): ?><article><span><?= e(ucfirst($profile)) ?></span><strong><?= e($description) ?></strong><small><?= e(implode(', ', AnalysisProfile::providers($profile))) ?></small></article><?php endforeach; ?></div></section>
<section class="panel"><div class="section-row"><div><h2>Provider health</h2><p>Live registry health and pinned versions.</p></div><span class="count-mark"><?= count($registry) ?></span></div><div class="relationship-list"><?php foreach ($registry as $provider): ?><div><span><?= e($provider['available'] ? 'Ready' : 'Optional') ?></span><strong><?= e($provider['id']) ?> <?= e($provider['version']) ?></strong><small><?= e($provider['health']['message'] ?? 'No health message.') ?> · <?= e(implode(', ', $provider['capabilities'])) ?></small></div><?php endforeach; ?></div></section>
<?php if ($job !== null): ?><section class="panel"><div class="section-row"><div><h2>Latest job</h2><p><?= e($job['analysis_profile']) ?> · <?= e($job['state']) ?> · <?= count(json_decode((string) ($job['changed_paths_json'] ?? '[]'), true) ?: []) ?> Git-changed paths</p></div></div><div class="relationship-list"><?php foreach ($job['steps'] as $step): ?><div><span><?= e($step['state']) ?></span><strong><?= e($step['provider_id']) ?> <?= e($step['provider_version']) ?></strong><small><?= (int) $step['duration_ms'] ?> ms · <?= (int) $step['files_analyzed'] ?> files<?= $step['cache_hit'] ? ' · cache hit' : '' ?><?= $step['incremental'] ? ' · incremental neighborhood' : '' ?></small></div><?php endforeach; ?></div></section><?php endif; ?>
<section class="panel"><details><summary>Runtime doctor details</summary><div class="relationship-list"><?php foreach ($doctor as $check): ?><div><span><?= e($check['status']) ?></span><strong><?= e($check['name']) ?> <?= e($check['version'] ?? '') ?></strong><small><?= e($check['message']) ?></small></div><?php endforeach; ?></div></details></section></section>
<?php require __DIR__ . '/../views/footer.php'; ?>

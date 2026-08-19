<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
$projectId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$project = $projectId === false || $projectId === null ? null : Project::findForUser((int) $projectId, Auth::id());
if ($project === null) { http_response_code(404); exit('Project not found.'); }
$targetType = isset($_GET['type']) && is_string($_GET['type']) ? $_GET['type'] : 'feature';
if (!in_array($targetType, ['feature', 'symbol', 'file', 'route', 'table'], true)) $targetType = 'feature';
$target = isset($_GET['target']) && is_string($_GET['target']) ? trim(substr($_GET['target'], 0, 160)) : '';
$request = '';
if (is_post()) { verify_csrf(); $targetType = post_string('type'); $target = substr(post_string('target'), 0, 160); $request = substr(post_string('request'), 0, 1000); }
if (!in_array($targetType, ['feature', 'symbol', 'file', 'route', 'table'], true)) $targetType = 'feature';
$trace = $target === '' ? FeatureTracer::trace((int) $project['id'], '') : FeatureTracer::trace((int) $project['id'], $target);
$safePrompt = $request === '' ? null : PromptSafetyService::build((int) $project['id'], $request . ($target === '' ? '' : ' Target ' . $targetType . ': ' . $target . '.'));
$risk = $trace['tables'] !== [] || $trace['services'] !== [] || preg_match('/auth|payment|delete|upload|admin/i', $target) ? 'high' : ($trace['hops'] !== [] ? 'medium' : 'unknown');
$tests = ['Run the existing tests nearest the selected files and symbols.', 'Verify the changed behavior through its entry route or UI flow.'];
if ($trace['tables'] !== []) $tests[] = 'Test database compatibility and migrations against disposable data.';
if ($trace['services'] !== []) $tests[] = 'Test external-service failure and retry behavior without exposing credentials.';
$pageTitle = 'Change ' . $project['name']; $activePage = 'dashboard'; $activeProjectSection = 'change';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell v2-shell"><div class="breadcrumb"><a href="<?= e(url('project.php?id=' . (int) $project['id'])) ?>"><?= e($project['name']) ?></a><span>/</span><span>Change</span></div><div class="project-heading compact"><div><p class="landing-kicker">Change</p><h1>Know the blast radius before editing.</h1><p>Select a feature, symbol, file, route, or table. The plan stays grounded in stored graph evidence.</p></div></div><?php require __DIR__ . '/../views/project-nav.php'; ?><form method="post" class="trace-search change-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label><span>Target type</span><select name="type"><?php foreach (['feature', 'symbol', 'file', 'route', 'table'] as $type): ?><option <?= $targetType === $type ? 'selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?></select></label><label><span>Target</span><input name="target" value="<?= e($target) ?>" placeholder="Login, POST /api/users, users, AuthService"></label><label><span>Requested change</span><textarea name="request" maxlength="1000" placeholder="Describe the behavior you want to change."><?= e($request) ?></textarea></label><button class="button button-primary" type="submit">Build change plan</button></form>
<?php if ($target !== ''): ?><section class="trace-status"><div><span>Purpose evidence</span><strong><?= count($trace['symbols']) + count($trace['entry_points']) ?></strong></div><div><span>Dependency hops</span><strong><?= count($trace['hops']) ?></strong></div><div><span>Files in radius</span><strong><?= count($trace['files']) ?></strong></div><div><span>Data boundaries</span><strong><?= count($trace['tables']) ?></strong></div><div><span>Edit risk</span><strong><?= e($risk) ?></strong></div></section><section class="content-grid"><article class="panel"><h2>Purpose and dependencies</h2><p>This target matched <?= count($trace['symbols']) ?> symbols and <?= count($trace['entry_points']) ?> entry points with <?= e($trace['confidence']) ?> trace confidence.</p><div class="boundary-list"><?php foreach (array_slice($trace['files'], 0, 14) as $file): ?><a href="<?= e(url('file.php?project=' . (int) $project['id'] . '&id=' . (int) $file['id'])) ?>"><strong><?= e($file['path']) ?></strong><span><?= e($file['reason']) ?></span></a><?php endforeach; ?></div></article><aside class="panel"><h2>Risks and tests</h2><p>Dynamic dispatch and runtime configuration can extend this static blast radius.</p><ul><?php foreach ($tests as $test): ?><li><?= e($test) ?></li><?php endforeach; ?></ul><a class="button button-secondary" href="<?= e(url('feature.php?id=' . (int) $project['id'] . '&q=' . urlencode($target))) ?>">Inspect full trace</a></aside></section><?php endif; ?>
<?php if ($safePrompt !== null): ?><section class="panel"><div class="section-row"><div><h2>Safe prompt</h2><p>Generated locally from the selected graph evidence.</p></div></div><textarea class="prompt-output" readonly rows="16"><?= e($safePrompt['prompt']) ?></textarea></section><?php endif; ?></section>
<?php require __DIR__ . '/../views/footer.php'; ?>

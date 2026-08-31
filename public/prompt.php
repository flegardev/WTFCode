<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
$projectId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$project = $projectId === false || $projectId === null ? null : Project::findForUser((int) $projectId, Auth::id());
if ($project === null) { http_response_code(404); exit('Project not found.'); }
$request = '';
$result = null;
if (is_post()) {
    verify_csrf();
    $request = post_string('request');
    if (text_length($request) > 1000) $request = substr($request, 0, 1000);
    $result = PromptSafetyService::build((int) $project['id'], $request);
}
$pageTitle = 'Safe prompt for ' . $project['name'];
$activePage = 'dashboard';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell narrow"><div class="breadcrumb"><a href="<?= e(url('project.php?id=' . (int) $project['id'])) ?>"><?= e($project['name']) ?></a><span>/</span><span>Safe prompt</span></div><div class="project-heading compact"><div><p class="landing-kicker">Prompt builder</p><h1>Give an AI the right context.</h1><p>This helper makes a reviewable prompt from detected systems and file metadata. It does not send your request or repository source to an AI provider.</p></div></div><section class="ask-card"><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label for="request">What change do you want to make?</label><textarea id="request" name="request" rows="4" maxlength="1000" placeholder="Add a settings screen where users can update their display name." required><?= e($request) ?></textarea><button class="button button-primary" type="submit">Build safe prompt</button></form><?php if ($result !== null): ?><article class="answer-card"><p class="landing-kicker">Generated context</p><textarea class="prompt-output" readonly rows="15" aria-label="Generated safe prompt"><?= e($result['prompt']) ?></textarea><h2>Why these constraints are present</h2><ul class="check-list"><?php foreach ($result['notes'] as $note): ?><li><?= e($note) ?></li><?php endforeach; ?></ul><?php if ($result['evidence'] !== []): ?><div class="answer-evidence"><?php foreach ($result['evidence'] as $file): ?><a href="<?= e(url('file.php?project=' . (int) $project['id'] . '&id=' . (int) $file['id'])) ?>"><strong><?= e($file['path']) ?></strong><span><?= e($file['plain_summary']) ?></span></a><?php endforeach; ?></div><?php endif; ?></article><?php endif; ?></section></section>
<?php require __DIR__ . '/../views/footer.php'; ?>

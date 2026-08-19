<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
$projectId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$project = $projectId === false || $projectId === null ? null : Project::findForUser((int) $projectId, Auth::id());
if ($project === null) { http_response_code(404); exit('Project not found.'); }
$question = '';
$result = null;
if (is_post()) {
    verify_csrf();
    $question = post_string('question');
    if (text_length($question) > 500) { $question = substr($question, 0, 500); }
    $result = ExplanationService::answerQuestion((int) $project['id'], $question);
}
$pageTitle = 'Ask ' . $project['name'];
$activePage = 'dashboard';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell narrow"><div class="breadcrumb"><a href="<?= e(url('project.php?id=' . (int) $project['id'])) ?>"><?= e($project['name']) ?></a><span>/</span><span>Ask</span></div><div class="project-heading compact"><div><p class="landing-kicker">Ask your project</p><h1>Start with the evidence.</h1><p>Answers are generated from this repository’s scan data. They do not invent code behavior or call an external AI service.</p></div></div><section class="ask-card"><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label for="question">What do you want to understand?</label><textarea id="question" name="question" rows="4" maxlength="500" placeholder="Why do I have middleware? Where does login happen? Can I delete this folder?" required><?= e($question) ?></textarea><button class="button button-primary" type="submit">Ask this project</button></form><?php if ($result !== null): ?><article class="answer-card"><p class="landing-kicker">Evidence-based answer</p><h2><?= e($result['answer']) ?></h2><?php if (($result['trace']['symbols'] ?? []) !== []): ?><h3>Matched symbols</h3><div class="answer-evidence"><?php foreach (array_slice($result['trace']['symbols'], 0, 12) as $symbol): ?><a href="<?= e(url('symbol.php?project=' . (int) $project['id'] . '&id=' . (int) $symbol['id'])) ?>"><strong><?= e($symbol['name']) ?></strong><span><?= e(str_replace('_', ' ', $symbol['type'])) ?> in <?= e($symbol['path']) ?></span></a><?php endforeach; ?></div><?php endif; ?><?php if ($result['evidence'] !== []): ?><h3>Evidence files</h3><div class="answer-evidence"><?php foreach ($result['evidence'] as $file): ?><a href="<?= e(url('file.php?project=' . (int) $project['id'] . '&id=' . (int) $file['id'])) ?>"><strong><?= e($file['path']) ?></strong><span><?= e($file['plain_summary'] ?? $file['role_name']) ?></span></a><?php endforeach; ?></div><?php endif; ?></article><?php endif; ?></section></section>
<?php require __DIR__ . '/../views/footer.php'; ?>
